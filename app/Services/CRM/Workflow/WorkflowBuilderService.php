<?php

namespace App\Services\CRM\Workflow;

/**
 * Visual Workflow Builder Service
 * 
 * يبني وينفذ سير عمل مرئي (Visual Workflow) بدون كود
 * يدعم السحب والإفلات، الشروط، الأتمتة، والتكاملات
 */
class WorkflowBuilderService
{
    /**
     * @var array قائمة بجميع العقد (Nodes) المتاحة
     */
    private array $availableNodes = [];

    /**
     * @var array سجل تنفيذ السير
     */
    private array $executionLog = [];

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->initializeNodes();
    }

    /**
     * تهيئة جميع أنواع العقد المتاحة
     */
    private function initializeNodes(): void
    {
        $this->availableNodes = [
            // Triggers
            'trigger' => [
                'form_submission' => ['label' => 'Form Submission', 'icon' => 'form', 'category' => 'trigger'],
                'email_received' => ['label' => 'Email Received', 'icon' => 'email', 'category' => 'trigger'],
                'lead_created' => ['label' => 'Lead Created', 'icon' => 'user-plus', 'category' => 'trigger'],
                'deal_stage_changed' => ['label' => 'Deal Stage Changed', 'icon' => 'exchange', 'category' => 'trigger'],
                'task_completed' => ['label' => 'Task Completed', 'icon' => 'check-circle', 'category' => 'trigger'],
                'webhook_received' => ['label' => 'Webhook Received', 'icon' => 'webhook', 'category' => 'trigger'],
                'schedule_time' => ['label' => 'Schedule Time', 'icon' => 'clock', 'category' => 'trigger'],
                'customer_birthday' => ['label' => 'Customer Birthday', 'icon' => 'birthday-cake', 'category' => 'trigger'],
            ],
            
            // Actions
            'action' => [
                'send_email' => ['label' => 'Send Email', 'icon' => 'envelope', 'category' => 'action'],
                'send_sms' => ['label' => 'Send SMS', 'icon' => 'sms', 'category' => 'action'],
                'send_whatsapp' => ['label' => 'Send WhatsApp', 'icon' => 'whatsapp', 'category' => 'action'],
                'create_task' => ['label' => 'Create Task', 'icon' => 'tasks', 'category' => 'action'],
                'update_deal' => ['label' => 'Update Deal', 'icon' => 'briefcase', 'category' => 'action'],
                'add_tag' => ['label' => 'Add Tag', 'icon' => 'tag', 'category' => 'action'],
                'remove_tag' => ['label' => 'Remove Tag', 'icon' => 'tag-minus', 'category' => 'action'],
                'assign_user' => ['label' => 'Assign User', 'icon' => 'user-tag', 'category' => 'action'],
                'create_contact' => ['label' => 'Create Contact', 'icon' => 'user-plus', 'category' => 'action'],
                'update_contact' => ['label' => 'Update Contact', 'icon' => 'user-edit', 'category' => 'action'],
                'send_notification' => ['label' => 'Send Notification', 'icon' => 'bell', 'category' => 'action'],
                'add_to_campaign' => ['label' => 'Add to Campaign', 'icon' => 'bullhorn', 'category' => 'action'],
                'create_invoice' => ['label' => 'Create Invoice', 'icon' => 'file-invoice', 'category' => 'action'],
                'send_webhook' => ['label' => 'Send Webhook', 'icon' => 'cloud-upload', 'category' => 'action'],
                'delay_wait' => ['label' => 'Delay/Wait', 'icon' => 'hourglass', 'category' => 'action'],
            ],
            
            // Conditions
            'condition' => [
                'if_field_equals' => ['label' => 'If Field Equals', 'icon' => 'equals', 'category' => 'condition'],
                'if_field_contains' => ['label' => 'If Field Contains', 'icon' => 'search', 'category' => 'condition'],
                'if_field_greater' => ['label' => 'If Field Greater Than', 'icon' => 'arrow-up', 'category' => 'condition'],
                'if_field_less' => ['label' => 'If Field Less Than', 'icon' => 'arrow-down', 'category' => 'condition'],
                'if_date_passed' => ['label' => 'If Date Passed', 'icon' => 'calendar-check', 'category' => 'condition'],
                'if_tag_exists' => ['label' => 'If Tag Exists', 'icon' => 'tag-check', 'category' => 'condition'],
                'if_source_equals' => ['label' => 'If Source Equals', 'icon' => 'source', 'category' => 'condition'],
                'if_value_empty' => ['label' => 'If Value Empty', 'icon' => 'ban', 'category' => 'condition'],
            ],
            
            // Integrations
            'integration' => [
                'salesforce_sync' => ['label' => 'Sync to Salesforce', 'icon' => 'salesforce', 'category' => 'integration'],
                'hubspot_sync' => ['label' => 'Sync to HubSpot', 'icon' => 'hubspot', 'category' => 'integration'],
                'mailchimp_add' => ['label' => 'Add to Mailchimp', 'icon' => 'mailchimp', 'category' => 'integration'],
                'slack_notify' => ['label' => 'Notify Slack', 'icon' => 'slack', 'category' => 'integration'],
                'google_sheet_add' => ['label' => 'Add to Google Sheets', 'icon' => 'sheets', 'category' => 'integration'],
                'zapier_trigger' => ['label' => 'Trigger Zapier', 'icon' => 'zapier', 'category' => 'integration'],
            ],
        ];
    }

    /**
     * الحصول على جميع العقد المتاحة
     * 
     * @return array
     */
    public function getAvailableNodes(): array
    {
        return $this->availableNodes;
    }

    /**
     * إنشاء سير عمل جديد
     * 
     * @param string $name اسم السير
     * @param string $description وصف السير
     * @param int $tenantId معرف العميل
     * @param int $userId معرف المستخدم المنشئ
     * @return array بيانات السير المنشأ
     */
    public function createWorkflow(
        string $name,
        string $description,
        int $tenantId,
        int $userId
    ): array {
        $workflowId = $this->generateUniqueId();
        
        $workflow = [
            'id' => $workflowId,
            'name' => $name,
            'description' => $description,
            'tenant_id' => $tenantId,
            'created_by' => $userId,
            'status' => 'draft', // draft, active, paused, archived
            'nodes' => [],
            'connections' => [],
            'variables' => [],
            'triggers' => [],
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
            'version' => 1,
        ];

        // حفظ في قاعدة البيانات (محاكاة)
        $this->saveWorkflow($workflow);

        return $workflow;
    }

    /**
     * تحديث سير عمل موجود
     * 
     * @param string $workflowId معرف السير
     * @param array $data البيانات المحدثة
     * @return array|null السير المحدث أو null إذا لم يوجد
     */
    public function updateWorkflow(string $workflowId, array $data): ?array
    {
        $workflow = $this->getWorkflow($workflowId);
        
        if (!$workflow) {
            return null;
        }

        // تحديث الحقول المسموحة
        $allowedFields = ['name', 'description', 'status', 'nodes', 'connections', 'variables', 'triggers'];
        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $workflow[$field] = $data[$field];
            }
        }

        $workflow['updated_at'] = date('Y-m-d H:i:s');
        $workflow['version'] = ($workflow['version'] ?? 1) + 1;

        $this->saveWorkflow($workflow);

        return $workflow;
    }

    /**
     * إضافة عقدة إلى سير العمل
     * 
     * @param string $workflowId معرف السير
     * @param string $nodeType نوع العقدة (trigger, action, condition, integration)
     * @param string $nodeId معرف نوع العقدة
     * @param array $config تكوين العقدة
     * @param float|null $positionX موقع X
     * @param float|null $positionY موقع Y
     * @return array|null العقدة المضافة أو null
     */
    public function addNode(
        string $workflowId,
        string $nodeType,
        string $nodeId,
        array $config,
        ?float $positionX = null,
        ?float $positionY = null
    ): ?array {
        $workflow = $this->getWorkflow($workflowId);
        
        if (!$workflow || !isset($this->availableNodes[$nodeType][$nodeId])) {
            return null;
        }

        $node = [
            'node_id' => $this->generateUniqueId('node_'),
            'type' => $nodeType,
            'node_type' => $nodeId,
            'label' => $this->availableNodes[$nodeType][$nodeId]['label'],
            'icon' => $this->availableNodes[$nodeType][$nodeId]['icon'],
            'config' => $config,
            'position' => [
                'x' => $positionX ?? 0,
                'y' => $positionY ?? 0,
            ],
            'inputs' => $this->getNodeInputs($nodeType, $nodeId),
            'outputs' => $this->getNodeOutputs($nodeType, $nodeId),
            'created_at' => date('Y-m-d H:i:s'),
        ];

        $workflow['nodes'][] = $node;
        $workflow['updated_at'] = date('Y-m-d H:i:s');

        $this->saveWorkflow($workflow);

        return $node;
    }

    /**
     * ربط عقدتين في سير العمل
     * 
     * @param string $workflowId معرف السير
     * @param string $fromNodeId معرف العقدة المصدر
     * @param string $toNodeId معرف العقدة الهدف
     * @param string|null $outputPort منفذ الإخراج (للشروط)
     * @return array|null الاتصال أو null
     */
    public function connectNodes(
        string $workflowId,
        string $fromNodeId,
        string $toNodeId,
        ?string $outputPort = null
    ): ?array {
        $workflow = $this->getWorkflow($workflowId);
        
        if (!$workflow) {
            return null;
        }

        // التحقق من وجود العقد
        $fromNode = $this->findNodeById($workflow['nodes'], $fromNodeId);
        $toNode = $this->findNodeById($workflow['nodes'], $toNodeId);

        if (!$fromNode || !$toNode) {
            return null;
        }

        $connection = [
            'id' => $this->generateUniqueId('conn_'),
            'from' => $fromNodeId,
            'to' => $toNodeId,
            'from_port' => $outputPort ?? 'default',
            'to_port' => 'default',
            'created_at' => date('Y-m-d H:i:s'),
        ];

        $workflow['connections'][] = $connection;
        $workflow['updated_at'] = date('Y-m-d H:i:s');

        $this->saveWorkflow($workflow);

        return $connection;
    }

    /**
     * تنفيذ سير العمل
     * 
     * @param string $workflowId معرف السير
     * @param array $context سياق التنفيذ (البيانات المتاحة)
     * @return array نتيجة التنفيذ
     */
    public function executeWorkflow(string $workflowId, array $context = []): array
    {
        $workflow = $this->getWorkflow($workflowId);
        
        if (!$workflow) {
            return [
                'success' => false,
                'error' => 'Workflow not found',
                'executed_nodes' => [],
            ];
        }

        if ($workflow['status'] !== 'active') {
            return [
                'success' => false,
                'error' => 'Workflow is not active',
                'executed_nodes' => [],
            ];
        }

        $this->executionLog = [];
        $startTime = microtime(true);

        // البحث عن عقد البداية (Triggers)
        $triggerNodes = array_filter($workflow['nodes'], function($node) {
            return $node['type'] === 'trigger';
        });

        if (empty($triggerNodes)) {
            return [
                'success' => false,
                'error' => 'No trigger nodes found',
                'executed_nodes' => [],
            ];
        }

        // تنفيذ كل Trigger ومطابقته مع السياق
        foreach ($triggerNodes as $triggerNode) {
            if ($this->triggerMatches($triggerNode, $context)) {
                // بدء التنفيذ من هذا الـ trigger
                $result = $this->executeFromNode($workflow, $triggerNode['node_id'], $context);
                
                if ($result['success']) {
                    return [
                        'success' => true,
                        'workflow_id' => $workflowId,
                        'trigger_node' => $triggerNode['node_id'],
                        'executed_nodes' => $result['executed_nodes'],
                        'execution_log' => $this->executionLog,
                        'execution_time' => round((microtime(true) - $startTime) * 1000, 2) . 'ms',
                        'completed_at' => date('Y-m-d H:i:s'),
                    ];
                }
            }
        }

        return [
            'success' => false,
            'error' => 'No matching triggers found',
            'executed_nodes' => [],
        ];
    }

    /**
     * التحقق من تطابق Trigger مع السياق
     * 
     * @param array $triggerNode العقدة
     * @param array $context السياق
     * @return bool
     */
    private function triggerMatches(array $triggerNode, array $context): bool
    {
        $config = $triggerNode['config'];
        
        switch ($triggerNode['node_type']) {
            case 'form_submission':
                return isset($context['event']) && $context['event'] === 'form_submitted';
            
            case 'email_received':
                return isset($context['event']) && $context['event'] === 'email_received';
            
            case 'lead_created':
                return isset($context['event']) && $context['event'] === 'lead_created';
            
            case 'deal_stage_changed':
                if (!isset($context['event']) || $context['event'] !== 'deal_stage_changed') {
                    return false;
                }
                if (isset($config['specific_stage'])) {
                    return $context['new_stage'] === $config['specific_stage'];
                }
                return true;
            
            case 'schedule_time':
                // التحقق من الوقت المحدد
                if (isset($config['scheduled_time'])) {
                    $scheduledTime = strtotime($config['scheduled_time']);
                    $currentTime = time();
                    return abs($currentTime - $scheduledTime) < 60; // خلال دقيقة
                }
                return false;
            
            default:
                return true;
        }
    }

    /**
     * تنفيذ السير من عقدة معينة
     * 
     * @param array $workflow بيانات السير
     * @param string $nodeId معرف العقدة
     * @param array $context السياق
     * @return array نتيجة التنفيذ
     */
    private function executeFromNode(array $workflow, string $nodeId, array $context): array
    {
        $executedNodes = [];
        $queue = [$nodeId];
        $visited = [];

        while (!empty($queue)) {
            $currentNodeId = array_shift($queue);
            
            if (in_array($currentNodeId, $visited)) {
                continue;
            }

            $currentNode = $this->findNodeById($workflow['nodes'], $currentNodeId);
            
            if (!$currentNode) {
                continue;
            }

            $visited[] = $currentNodeId;

            // تنفيذ العقدة
            $executionResult = $this->executeNode($currentNode, $context);
            
            if (!$executionResult['success']) {
                return [
                    'success' => false,
                    'error' => "Failed to execute node {$currentNode['label']}: {$executionResult['error']}",
                    'executed_nodes' => $executedNodes,
                ];
            }

            $executedNodes[] = [
                'node_id' => $currentNodeId,
                'type' => $currentNode['type'],
                'node_type' => $currentNode['node_type'],
                'label' => $currentNode['label'],
                'executed_at' => date('Y-m-d H:i:s'),
                'result' => $executionResult['data'] ?? null,
            ];

            $this->executionLog[] = [
                'node_id' => $currentNodeId,
                'label' => $currentNode['label'],
                'status' => 'success',
                'timestamp' => date('Y-m-d H:i:s'),
            ];

            // تحديث السياق بنتائج العقدة
            if (isset($executionResult['data'])) {
                $context = array_merge($context, $executionResult['data']);
            }

            // العثور على العقد التالية
            $nextNodes = $this->getNextNodes($workflow, $currentNodeId, $context);
            
            foreach ($nextNodes as $nextNode) {
                if (!in_array($nextNode['node_id'], $visited)) {
                    $queue[] = $nextNode['node_id'];
                }
            }
        }

        return [
            'success' => true,
            'executed_nodes' => $executedNodes,
        ];
    }

    /**
     * تنفيذ عقدة محددة
     * 
     * @param array $node العقدة
     * @param array $context السياق
     * @return array نتيجة التنفيذ
     */
    private function executeNode(array $node, array $context): array
    {
        switch ($node['type']) {
            case 'trigger':
                return $this->executeTrigger($node, $context);
            
            case 'action':
                return $this->executeAction($node, $context);
            
            case 'condition':
                return $this->executeCondition($node, $context);
            
            case 'integration':
                return $this->executeIntegration($node, $context);
            
            default:
                return ['success' => false, 'error' => 'Unknown node type'];
        }
    }

    /**
     * تنفيذ Trigger
     */
    private function executeTrigger(array $node, array $context): array
    {
        return ['success' => true, 'data' => ['trigger_executed' => $node['node_type']]];
    }

    /**
     * تنفيذ Action
     */
    private function executeAction(array $node, array $context): array
    {
        $config = $node['config'];
        
        switch ($node['node_type']) {
            case 'send_email':
                // محاكاة إرسال إيميل
                return [
                    'success' => true,
                    'data' => [
                        'email_sent' => true,
                        'to' => $this->resolveVariable($config['to'] ?? '', $context),
                        'subject' => $this->resolveVariable($config['subject'] ?? '', $context),
                    ]
                ];
            
            case 'send_sms':
                return [
                    'success' => true,
                    'data' => ['sms_sent' => true, 'to' => $this->resolveVariable($config['to'] ?? '', $context)]
                ];
            
            case 'send_whatsapp':
                return [
                    'success' => true,
                    'data' => ['whatsapp_sent' => true, 'to' => $this->resolveVariable($config['to'] ?? '', $context)]
                ];
            
            case 'create_task':
                return [
                    'success' => true,
                    'data' => [
                        'task_created' => true,
                        'title' => $this->resolveVariable($config['title'] ?? '', $context),
                        'assigned_to' => $config['assigned_to'] ?? null,
                    ]
                ];
            
            case 'add_tag':
                return [
                    'success' => true,
                    'data' => ['tag_added' => $config['tag_name'] ?? '']
                ];
            
            case 'delay_wait':
                $delaySeconds = $config['delay_seconds'] ?? 0;
                // في الواقع: sleep($delaySeconds)
                return [
                    'success' => true,
                    'data' => ['delayed' => true, 'seconds' => $delaySeconds]
                ];
            
            default:
                return ['success' => true, 'data' => ['action_executed' => $node['node_type']]];
        }
    }

    /**
     * تنفيذ Condition
     */
    private function executeCondition(array $node, array $context): array
    {
        $config = $node['config'];
        $result = false;

        switch ($node['node_type']) {
            case 'if_field_equals':
                $fieldValue = $this->resolveVariable($config['field'] ?? '', $context);
                $result = $fieldValue == $config['value'];
                break;
            
            case 'if_field_contains':
                $fieldValue = $this->resolveVariable($config['field'] ?? '', $context);
                $result = strpos($fieldValue, $config['value'] ?? '') !== false;
                break;
            
            case 'if_field_greater':
                $fieldValue = floatval($this->resolveVariable($config['field'] ?? '', $context));
                $result = $fieldValue > floatval($config['value'] ?? 0);
                break;
            
            case 'if_field_less':
                $fieldValue = floatval($this->resolveVariable($config['field'] ?? '', $context));
                $result = $fieldValue < floatval($config['value'] ?? 0);
                break;
            
            case 'if_tag_exists':
                $tags = $context['tags'] ?? [];
                $result = in_array($config['tag_name'] ?? '', $tags);
                break;
            
            case 'if_value_empty':
                $fieldValue = $this->resolveVariable($config['field'] ?? '', $context);
                $result = empty($fieldValue);
                break;
        }

        return [
            'success' => true,
            'data' => [
                'condition_result' => $result,
                'path' => $result ? 'true' : 'false'
            ]
        ];
    }

    /**
     * تنفيذ Integration
     */
    private function executeIntegration(array $node, array $context): array
    {
        // محاكاة التكاملات
        return [
            'success' => true,
            'data' => ['integration_executed' => $node['node_type'], 'synced' => true]
        ];
    }

    /**
     * الحصول على العقد التالية بناءً على الاتصالات والشروط
     */
    private function getNextNodes(array $workflow, string $currentNodeId, array $context): array
    {
        $nextNodes = [];
        
        // العثور على جميع الاتصالات من هذه العقدة
        $connections = array_filter($workflow['connections'], function($conn) use ($currentNodeId) {
            return $conn['from'] === $currentNodeId;
        });

        foreach ($connections as $connection) {
            $targetNode = $this->findNodeById($workflow['nodes'], $connection['to']);
            
            if (!$targetNode) {
                continue;
            }

            // إذا كانت العقدة الحالية شرط، التحقق من المسار الصحيح
            $currentNode = $this->findNodeById($workflow['nodes'], $currentNodeId);
            
            if ($currentNode && $currentNode['type'] === 'condition') {
                $conditionResult = $this->evaluateCondition($currentNode, $context);
                
                // التحقق مما إذا كان المسار يتطابق مع نتيجة الشرط
                if ($connection['from_port'] === 'true' && !$conditionResult) {
                    continue;
                }
                if ($connection['from_port'] === 'false' && $conditionResult) {
                    continue;
                }
            }

            $nextNodes[] = $targetNode;
        }

        return $nextNodes;
    }

    /**
     * تقييم شرط
     */
    private function evaluateCondition(array $node, array $context): bool
    {
        $result = $this->executeCondition($node, $context);
        return $result['data']['condition_result'] ?? false;
    }

    /**
     * حل المتغيرات في النصوص
     */
    private function resolveVariable(string $text, array $context): string
    {
        // دعم صيغة {{variable}}
        return preg_replace_callback('/\{\{([^}]+)\}\}/', function($matches) use ($context) {
            $varName = trim($matches[1]);
            
            // دعم النقاط للوصول للمتغيرات المتداخلة
            $parts = explode('.', $varName);
            $value = $context;
            
            foreach ($parts as $part) {
                if (is_array($value) && isset($value[$part])) {
                    $value = $value[$part];
                } else {
                    return $matches[0]; // إرجاع النص الأصلي إذا لم يوجد
                }
            }
            
            return is_array($value) ? json_encode($value) : strval($value);
        }, $text);
    }

    /**
     * البحث عن عقدة بمعرف معين
     */
    private function findNodeById(array $nodes, string $nodeId): ?array
    {
        foreach ($nodes as $node) {
            if ($node['node_id'] === $nodeId) {
                return $node;
            }
        }
        return null;
    }

    /**
     * الحصول على مدخلات العقدة
     */
    private function getNodeInputs(string $nodeType, string $nodeId): array
    {
        // تعريف المدخلات المطلوبة لكل نوع عقدة
        $inputs = [];
        
        if ($nodeType === 'trigger') {
            $inputs = []; // Triggers لا تحتاج مدخلات
        } elseif ($nodeType === 'action') {
            switch ($nodeId) {
                case 'send_email':
                    $inputs = ['to', 'subject', 'body'];
                    break;
                case 'send_sms':
                case 'send_whatsapp':
                    $inputs = ['to', 'message'];
                    break;
                case 'create_task':
                    $inputs = ['title', 'description', 'assigned_to', 'due_date'];
                    break;
                case 'add_tag':
                case 'remove_tag':
                    $inputs = ['tag_name'];
                    break;
                case 'delay_wait':
                    $inputs = ['delay_seconds'];
                    break;
            }
        } elseif ($nodeType === 'condition') {
            $inputs = ['field', 'value'];
        }

        return $inputs;
    }

    /**
     * الحصول على مخرجات العقدة
     */
    private function getNodeOutputs(string $nodeType, string $nodeId): array
    {
        if ($nodeType === 'condition') {
            return ['true', 'false'];
        }
        
        return ['default'];
    }

    /**
     * توليد معرف فريد
     */
    private function generateUniqueId(string $prefix = ''): string
    {
        return $prefix . bin2hex(random_bytes(8));
    }

    /**
     * حفظ سير العمل (محاكاة)
     */
    private function saveWorkflow(array $workflow): void
    {
        // في الواقع: حفظ في قاعدة البيانات
        // هنا: مجرد محاكاة
        file_put_contents(
            "/workspace/storage/workflows/{$workflow['id']}.json",
            json_encode($workflow, JSON_PRETTY_PRINT)
        );
    }

    /**
     * الحصول على سير عمل (محاكاة)
     */
    private function getWorkflow(string $workflowId): ?array
    {
        $filePath = "/workspace/storage/workflows/{$workflowId}.json";
        
        if (file_exists($filePath)) {
            return json_decode(file_get_contents($filePath), true);
        }
        
        return null;
    }

    /**
     * الحصول على جميع سير العمل لعميل معين
     */
    public function getWorkflows(int $tenantId, string $status = null): array
    {
        $workflows = [];
        $workflowDir = '/workspace/storage/workflows/';
        
        if (!is_dir($workflowDir)) {
            return [];
        }

        $files = glob($workflowDir . '*.json');
        
        foreach ($files as $file) {
            $workflow = json_decode(file_get_contents($file), true);
            
            if ($workflow && $workflow['tenant_id'] === $tenantId) {
                if ($status === null || $workflow['status'] === $status) {
                    $workflows[] = $workflow;
                }
            }
        }

        return $workflows;
    }

    /**
     * حذف سير عمل
     */
    public function deleteWorkflow(string $workflowId): bool
    {
        $filePath = "/workspace/storage/workflows/{$workflowId}.json";
        
        if (file_exists($filePath)) {
            return unlink($filePath);
        }
        
        return false;
    }

    /**
     * تفعيل سير عمل
     */
    public function activateWorkflow(string $workflowId): ?array
    {
        return $this->updateWorkflow($workflowId, ['status' => 'active']);
    }

    /**
     * إيقاف سير عمل مؤقتاً
     */
    public function pauseWorkflow(string $workflowId): ?array
    {
        return $this->updateWorkflow($workflowId, ['status' => 'paused']);
    }

    /**
     * أرشفة سير عمل
     */
    public function archiveWorkflow(string $workflowId): ?array
    {
        return $this->updateWorkflow($workflowId, ['status' => 'archived']);
    }

    /**
     * استيراد سير عمل من JSON
     */
    public function importWorkflow(string $jsonString, int $tenantId, int $userId): ?array
    {
        $data = json_decode($jsonString, true);
        
        if (!$data) {
            return null;
        }

        $workflow = $this->createWorkflow(
            $data['name'] ?? 'Imported Workflow',
            $data['description'] ?? '',
            $tenantId,
            $userId
        );

        // إضافة العقد
        if (isset($data['nodes'])) {
            foreach ($data['nodes'] as $nodeData) {
                $this->addNode(
                    $workflow['id'],
                    $nodeData['type'],
                    $nodeData['node_type'],
                    $nodeData['config'] ?? [],
                    $nodeData['position']['x'] ?? 0,
                    $nodeData['position']['y'] ?? 0
                );
            }
        }

        // إضافة الاتصالات
        if (isset($data['connections'])) {
            foreach ($data['connections'] as $connData) {
                $this->connectNodes(
                    $workflow['id'],
                    $connData['from'],
                    $connData['to'],
                    $connData['from_port'] ?? null
                );
            }
        }

        return $workflow;
    }

    /**
     * تصدير سير عمل إلى JSON
     */
    public function exportWorkflow(string $workflowId): ?string
    {
        $workflow = $this->getWorkflow($workflowId);
        
        if (!$workflow) {
            return null;
        }

        // إزالة معلومات خاصة قبل التصدير
        unset($workflow['tenant_id'], $workflow['created_by']);

        return json_encode($workflow, JSON_PRETTY_PRINT);
    }

    /**
     * التحقق من صحة سير العمل
     */
    public function validateWorkflow(string $workflowId): array
    {
        $workflow = $this->getWorkflow($workflowId);
        
        if (!$workflow) {
            return ['valid' => false, 'errors' => ['Workflow not found']];
        }

        $errors = [];

        // التحقق من وجود Trigger واحد على الأقل
        $hasTrigger = false;
        foreach ($workflow['nodes'] as $node) {
            if ($node['type'] === 'trigger') {
                $hasTrigger = true;
                break;
            }
        }

        if (!$hasTrigger) {
            $errors[] = 'Workflow must have at least one trigger node';
        }

        // التحقق من أن جميع العقد متصلة
        $connectedNodes = [];
        foreach ($workflow['connections'] as $connection) {
            $connectedNodes[] = $connection['from'];
            $connectedNodes[] = $connection['to'];
        }

        foreach ($workflow['nodes'] as $node) {
            if (!in_array($node['node_id'], $connectedNodes) && $node['type'] !== 'trigger') {
                $errors[] = "Node '{$node['label']}' is not connected";
            }
        }

        // التحقق من تكوين العقد
        foreach ($workflow['nodes'] as $node) {
            $requiredInputs = $this->getNodeInputs($node['type'], $node['node_type']);
            
            foreach ($requiredInputs as $input) {
                if (!isset($node['config'][$input])) {
                    $errors[] = "Node '{$node['label']}' missing required field: {$input}";
                }
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => [],
        ];
    }
}
