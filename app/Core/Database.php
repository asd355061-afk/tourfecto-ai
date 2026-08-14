<?php
/**
 * Tourfecto - Database Connection Class
 * اتصال PDO متقدم مع حماية قصوى ضد SQL Injection
 * @version 1.0.1
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 *
 * تعديل 2026-07-12: تصحيح bindValue() في query() و exec() —
 * كان يستخدم مفتاح المصفوفة مباشرة ($key يبدأ من 0)، بينما PDO يتطلب أن يبدأ
 * ترقيم الـ positional parameters (?) من 1، مما كان يسبب دائمًا الخطأ:
 * "PDOStatement::bindValue(): Argument #1 ($param) must be greater than or equal to 1"
 */

class Database {
    /**
     * @var PDO $connection - كائن الاتصال بقاعدة البيانات
     */
    private $connection;
    
    /**
     * @var Database|null $instance - نسخة Singleton
     */
    private static $instance = null;
    
    /**
     * @var array $queryLog - سجل الاستعلامات
     */
    private $queryLog = [];
    
    /**
     * @var bool $inTransaction - حالة المعاملة
     */
    private $inTransaction = false;
    
    /**
     * @var int $queryCount - عدد الاستعلامات المنفذة
     */
    private $queryCount = 0;
    
    /**
     * @var float $totalQueryTime - إجمالي وقت الاستعلامات
     */
    private $totalQueryTime = 0;
    
    /**
     * Constructor - تهيئة الاتصال بقاعدة البيانات (Singleton)
     */
    private function __construct() {
        $this->connect();
    }
    
    /**
     * إنشاء اتصال بقاعدة البيانات
     * @throws Exception
     */
    private function connect(): void {
        try {
            $dsn = sprintf(
                "mysql:host=%s;port=%s;dbname=%s;charset=%s",
                DB_HOST,
                DB_PORT,
                DB_NAME,
                DB_CHARSET
            );
            
            $options = DB_OPTIONS;
            
            if (isset(DB_OPTIONS[PDO::MYSQL_ATTR_SSL_CA]) && DB_OPTIONS[PDO::MYSQL_ATTR_SSL_CA]) {
                $options[PDO::MYSQL_ATTR_SSL_CA] = DB_OPTIONS[PDO::MYSQL_ATTR_SSL_CA];
                $options[PDO::MYSQL_ATTR_SSL_CERT] = DB_OPTIONS[PDO::MYSQL_ATTR_SSL_CERT] ?? null;
                $options[PDO::MYSQL_ATTR_SSL_KEY] = DB_OPTIONS[PDO::MYSQL_ATTR_SSL_KEY] ?? null;
            }
            
            $this->connection = new PDO($dsn, DB_USER, DB_PASS, $options);
            
            $this->connection->exec("SET NAMES 'utf8mb4' COLLATE 'utf8mb4_unicode_ci'");
            $this->connection->exec("SET SESSION time_zone = '+00:00'");
            $this->connection->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
            
        } catch (PDOException $e) {
            Logger::error('Database Connection Error', [
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
                'trace' => $e->getTraceAsString()
            ]);
            
            throw new Exception('Database connection failed. Please try again later.');
        }
    }
    
    public static function getInstance(): Database {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function getConnection(): PDO {
        return $this->connection;
    }
    
    /**
     * تنفيذ استعلام مع إعداد متقدم
     *
     * تصحيح (2026-08-05): عمليات التحليل بالـ AI بتعمل عدة طلبات HTTP طويلة
     * لـ Gemini (للموقع + كل منافس) قبل ما ترجع تكتب النتيجة في قاعدة
     * البيانات. لو مجموع وقت الطلبات ده عدّى قيمة wait_timeout بتاعة
     * MySQL (شائع جدًا يكون قصير على الاستضافات المشتركة)، السيرفر بيقفل
     * الاتصال من طرفه، وأي استعلام بعد كده كان بيفشل فورًا برسالة
     * "MySQL server has gone away" (SQLSTATE HY000, code 2006) - حتى لو
     * فيه دالة reconnect() جاهزة أصلاً في الكلاس، مكانتش بتتنادى من هنا.
     * دلوقتي: لو حصل هذا الخطأ تحديدًا، نعيد الاتصال ونعيد تنفيذ نفس
     * الاستعلام تلقائيًا (لحد DB_MAX_RETRIES مرة) قبل ما نرمي أي استثناء.
     */
    public function query(string $sql, array $params = [], int $fetchMode = PDO::FETCH_ASSOC) {
        $attempt = 0;

        while (true) {
            $attempt++;
            $startTime = microtime(true);
            $this->queryCount++;

            try {
                $stmt = $this->connection->prepare($sql);

                // تصحيح: بعض الاستدعاءات في المشروع تستخدم placeholders مسمّاة
                // (مثل :token أو :user_id) بينما كان الكود هنا يفترض دايمًا إنها
                // positional (?) ويحاول يعمل $key + 1 على المفتاح. لو المفتاح
                // نص غير رقمي زي ":token"، فده بيرمي في PHP 8:
                // TypeError: Unsupported operand types: string + int
                // فكانت أي استعلام يستخدم named placeholder بيفشل بالكامل.
                // الحل: لو المفتاح رقمي (استخدام ?) نضيف +1 زي الأول، ولو نص
                // (استخدام :name) نستخدمه زي ما هو كـ اسم للـ bind مباشرة.
                foreach ($params as $key => $value) {
                    $paramType = $this->determineParamType($value);
                    $bindKey = is_int($key) ? $key + 1 : $key;
                    $stmt->bindValue($bindKey, $value, $paramType);
                }

                $stmt->execute();

                $executionTime = microtime(true) - $startTime;
                $this->totalQueryTime += $executionTime;
                $this->logQuery($sql, $params, $executionTime);

                if ($executionTime > DB_SLOW_QUERY_THRESHOLD) {
                    Logger::warning('Slow query detected', [
                        'sql' => $sql,
                        'time' => $executionTime,
                        'threshold' => DB_SLOW_QUERY_THRESHOLD
                    ]);
                }

                $sqlUpper = strtoupper(trim($sql));

                if (strpos($sqlUpper, 'SELECT') === 0) {
                    return $stmt->fetchAll($fetchMode);
                } elseif (strpos($sqlUpper, 'INSERT') === 0) {
                    return (int) $this->connection->lastInsertId();
                } elseif (strpos($sqlUpper, 'UPDATE') === 0 || strpos($sqlUpper, 'DELETE') === 0) {
                    return $stmt->rowCount();
                } elseif (strpos($sqlUpper, 'REPLACE') === 0) {
                    return (int) $this->connection->lastInsertId();
                }

                return true;

            } catch (PDOException $e) {
                $canRetry = $attempt <= DB_MAX_RETRIES
                    && !$this->inTransaction
                    && $this->isConnectionLostError($e);

                if ($canRetry) {
                    Logger::warning('DB connection lost mid-request, reconnecting and retrying', [
                        'attempt' => $attempt,
                        'message' => $e->getMessage()
                    ]);
                    usleep((int) (DB_RETRY_DELAY * 1000000));
                    if ($this->reconnect()) {
                        continue; // نعيد المحاولة بنفس الاستعلام على اتصال جديد
                    }
                }

                Logger::error('Query Error', [
                    'sql' => $sql,
                    'params' => $params,
                    'message' => $e->getMessage(),
                    'code' => $e->getCode()
                ]);

                throw new Exception('Database query failed: ' . $e->getMessage());
            }
        }
    }
    
    /**
     * تنفيذ استعلام بدون إرجاع نتائج (للـ DDL)
     */
    public function exec(string $sql, array $params = []): bool {
        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                $stmt = $this->connection->prepare($sql);

                // نفس التصحيح: الترقيم في PDO يبدأ من 1
                foreach ($params as $key => $value) {
                    $paramType = $this->determineParamType($value);
                    $stmt->bindValue($key + 1, $value, $paramType);
                }

                return $stmt->execute();

            } catch (PDOException $e) {
                $canRetry = $attempt <= DB_MAX_RETRIES
                    && !$this->inTransaction
                    && $this->isConnectionLostError($e);

                if ($canRetry) {
                    Logger::warning('DB connection lost mid-request (exec), reconnecting and retrying', [
                        'attempt' => $attempt,
                        'message' => $e->getMessage()
                    ]);
                    usleep((int) (DB_RETRY_DELAY * 1000000));
                    if ($this->reconnect()) {
                        continue;
                    }
                }

                Logger::error('Exec Error', [
                    'sql' => $sql,
                    'params' => $params,
                    'message' => $e->getMessage()
                ]);

                return false;
            }
        }
    }

    /**
     * هل الاستثناء ده بسبب سقوط الاتصال (وليس خطأ في الاستعلام نفسه زي
     * عمود غلط أو قيد مكرر)؟ لو آه، إعادة المحاولة بعد reconnect منطقية
     * ومأمونة. لو خطأ تاني (زي Unknown column أو Duplicate entry)،
     * إعادة نفس الاستعلام هترمي نفس الخطأ تاني من غير أي فايدة.
     */
    private function isConnectionLostError(PDOException $e): bool {
        $code = (int) ($e->errorInfo[1] ?? $e->getCode());
        // 2006 = MySQL server has gone away
        // 2013 = Lost connection to MySQL server during query
        if (in_array($code, [2006, 2013], true)) {
            return true;
        }
        $message = $e->getMessage();
        return stripos($message, 'server has gone away') !== false
            || stripos($message, 'Lost connection') !== false
            || stripos($message, 'Error while sending') !== false;
    }
    
    private function determineParamType($value): int {
        if (is_int($value)) {
            return PDO::PARAM_INT;
        } elseif (is_bool($value)) {
            return PDO::PARAM_BOOL;
        } elseif (is_null($value)) {
            return PDO::PARAM_NULL;
        }
        return PDO::PARAM_STR;
    }
    
    public function beginTransaction(): bool {
        if (!$this->inTransaction) {
            $this->inTransaction = $this->connection->beginTransaction();
        }
        return $this->inTransaction;
    }
    
    public function commit(): bool {
        if ($this->inTransaction) {
            $result = $this->connection->commit();
            $this->inTransaction = false;
            return $result;
        }
        return false;
    }
    
    public function rollback(): bool {
        if ($this->inTransaction) {
            $result = $this->connection->rollback();
            $this->inTransaction = false;
            return $result;
        }
        return false;
    }
    
    public function transaction(callable $callback) {
        try {
            $this->beginTransaction();
            $result = $callback($this);
            $this->commit();
            return $result;
        } catch (Exception $e) {
            $this->rollback();
            throw $e;
        }
    }
    
    private function logQuery(string $sql, array $params, float $duration): void {
        if (!DB_QUERY_LOG_ENABLED) {
            return;
        }
        
        $this->queryLog[] = [
            'sql' => $sql,
            'params' => $params,
            'duration' => $duration,
            'timestamp' => microtime(true)
        ];
        
        if (count($this->queryLog) > DB_MAX_QUERY_LOG) {
            array_shift($this->queryLog);
        }
    }
    
    public function getQueryLog(): array {
        return $this->queryLog;
    }
    
    public function getQueryStats(): array {
        return [
            'count' => $this->queryCount,
            'total_time' => $this->totalQueryTime,
            'avg_time' => $this->queryCount > 0 ? $this->totalQueryTime / $this->queryCount : 0,
            'log_count' => count($this->queryLog)
        ];
    }
    
    public function isConnected(): bool {
        try {
            return (bool) $this->connection->query('SELECT 1');
        } catch (PDOException $e) {
            return false;
        }
    }
    
    public function reconnect(): bool {
        try {
            $this->connection = null;
            $this->connect();
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
    
    public function backup(string $filename = null): bool {
        if (!DB_BACKUP_ENABLED) {
            return false;
        }
        
        try {
            $filename = $filename ?? DB_NAME . '_' . date('Y-m-d_H-i-s') . '.sql';
            $path = DB_BACKUP_PATH . '/' . $filename;
            
            if (!is_dir(DB_BACKUP_PATH)) {
                mkdir(DB_BACKUP_PATH, 0755, true);
            }
            
            $command = sprintf(
                'mysqldump --host=%s --port=%s --user=%s --password=%s %s > %s',
                DB_HOST,
                DB_PORT,
                DB_USER,
                DB_PASS,
                DB_NAME,
                $path
            );
            
            if (DB_BACKUP_COMPRESS) {
                $path .= '.gz';
                $command .= ' | gzip > ' . $path;
            }
            
            exec($command, $output, $returnCode);
            
            Logger::info('Database backup created', [
                'filename' => $filename,
                'size' => filesize($path)
            ]);
            
            return $returnCode === 0;
            
        } catch (Exception $e) {
            Logger::error('Database backup failed', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    private function __clone() {}
    
    public function __wakeup() {
        throw new Exception('Cannot unserialize singleton');
    }
    
    public function __destruct() {
        $this->connection = null;
    }
}