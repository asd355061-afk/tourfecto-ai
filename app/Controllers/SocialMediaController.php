<?php

/**
 * Tourfecto - Social Media Controller
 * لوحة إدارة السوشيال ميديا (من ai-marketing-automation-hub، مُعاد بناؤها
 * فوق platform_connections/social_posts/social_post_targets الموحّدة)
 * @version 1.0.0
 */
class SocialMediaController extends Controller
{
    /** @var SocialPostService */
    private $service;

    public function __construct()
    {
        parent::__construct();
        $this->service = new SocialPostService();
    }

    /** GET /social */
    public function index(array $params = []): array
    {
        $body = <<<HTML
        <div class="p-toolbar">
            <button class="p-btn" onclick="document.getElementById('newPostModal').classList.add('open')">+ منشور جديد</button>
        </div>
        <div class="p-card no-pad">
            <div class="p-table-scroll"><table class="p-table" id="postsTable">
                <thead><tr><th>المحتوى</th><th>الحالة</th><th>تاريخ الإنشاء</th></tr></thead>
                <tbody><tr class="p-loading-row"><td colspan="3">جارِ التحميل...</td></tr></tbody>
            </table></div>
        </div>

        <div class="p-card no-pad" style="margin-top:18px;">
            <div class="p-card-head" style="padding:18px 20px 0;"><h3>📅 تقويم المحتوى</h3><span class="p-card-sub">آخر أسبوع + الشهر الجاي</span></div>
            <div class="p-table-scroll"><table class="p-table" id="calendarTable">
                <thead><tr><th>المحتوى</th><th>الموعد</th><th>الحالة</th></tr></thead>
                <tbody><tr class="p-loading-row"><td colspan="3">جارِ التحميل...</td></tr></tbody>
            </table></div>
        </div>

        <div class="p-modal-overlay" id="newPostModal">
            <div class="p-modal">
                <div class="p-modal-head"><h3>منشور جديد</h3><button class="p-modal-close" onclick="document.getElementById('newPostModal').classList.remove('open')">×</button></div>
                <div class="p-modal-body">
                    <label>الموضوع (لتوليد نص بالذكاء الاصطناعي)</label>
                    <input type="text" id="topicInput" class="p-select" style="width:100%;margin-bottom:10px;" placeholder="مثال: عرض خاص لرحلات الغردقة">
                    <button class="p-btn outline" onclick="generateCaption()">✨ توليد نص بالذكاء الاصطناعي</button>
                    <label style="margin-top:14px;display:block;">نص المنشور</label>
                    <textarea id="contentInput" rows="5" style="width:100%;" class="p-select"></textarea>
                    <label style="margin-top:14px;display:block;">انشر على</label>
                    <div id="targetsList" class="p-cell-muted">جارِ تحميل الصفحات المتصلة...</div>
                </div>
                <div class="p-modal-foot">
                    <button class="p-btn" onclick="createPost()">نشر / حفظ كمسودة</button>
                </div>
            </div>
        </div>
HTML;

        $script = <<<'JS'
(function () {
    const P = window.Panel;
    const esc = P.esc, fetchJSON = P.fetchJSON;

    async function loadTargets() {
        const res = await fetchJSON('/api/social/connections');
        const box = document.getElementById('targetsList');
        if (res.success && res.data.connections && res.data.connections.length) {
            box.innerHTML = res.data.connections.map(c => `
                <label style="display:flex;align-items:center;gap:8px;margin:6px 0;">
                    <input type="checkbox" class="targetCheckbox" value="${c.id}">
                    ${c.platform === 'facebook' ? '📘' : '📸'} ${esc(c.name)}
                </label>`).join('');
        } else {
            box.innerHTML = 'مفيش صفحات فيسبوك/انستجرام متصلة لسه. اربط حساب Meta الأول من <a href="/ads">صفحة الإعلانات</a> (نفس الربط بيوصّل الصفحات تلقائيًا).';
        }
    }

    async function load() {
        const res = await fetchJSON('/api/social/posts');
        const tbody = document.querySelector('#postsTable tbody');
        if (res.success && res.data.posts && res.data.posts.length) {
            tbody.innerHTML = res.data.posts.map(p => `
                <tr><td>${esc((p.content || '').slice(0, 80))}</td><td>${esc(p.status)}</td><td class="p-cell-muted">${esc(p.created_at)}</td></tr>
            `).join('');
        } else {
            tbody.innerHTML = '<tr><td colspan="3" class="p-empty">لا يوجد منشورات بعد</td></tr>';
        }
    }

    async function loadCalendar() {
        const res = await fetchJSON('/api/social/calendar');
        const tbody = document.querySelector('#calendarTable tbody');
        const rows = (res.success && res.data.items) || [];
        const statusPill = { pending: '<span class="pill">مجدول</span>', scheduled: '<span class="pill">مجدول</span>', publishing: '<span class="pill orange">جارِ النشر</span>', published: '<span class="pill green">✔ اتنشر</span>', failed: '<span class="pill red">فشل</span>' };
        tbody.innerHTML = rows.length ? rows.map(r => `
            <tr><td>${esc((r.content || '').slice(0, 60))}</td><td>${esc(r.scheduled_at || '-')}</td><td>${statusPill[r.status] || esc(r.status)}</td></tr>
        `).join('') : '<tr><td colspan="3" class="p-cell-muted">مفيش محتوى مجدول حاليًا</td></tr>';
    }

    window.generateCaption = async function () {
        const topic = document.getElementById('topicInput').value.trim();
        if (!topic) return;
        const res = await fetchJSON('/api/social/generate-caption', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ topic, platform: 'instagram' }) });
        if (res.success) {
            document.getElementById('contentInput').value = res.data.content;
        } else {
            alert(res.error || 'فشل التوليد');
        }
    };

    window.createPost = async function () {
        const content = document.getElementById('contentInput').value.trim();
        if (!content) return;
        const targets = Array.from(document.querySelectorAll('.targetCheckbox:checked')).map(cb => ({ platform_connection_id: parseInt(cb.value, 10) }));
        const res = await fetchJSON('/api/social/posts', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ content, targets }) });
        if (res.success) {
            document.getElementById('newPostModal').classList.remove('open');
            load();
        } else {
            alert(res.error || 'فشل الحفظ');
        }
    };

    load();
    loadCalendar();
    loadTargets();
})();
JS;

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('social', 'السوشيال ميديا', 'إدارة ونشر محتوى السوشيال ميديا عبر كل المنصات المربوطة', $body, $script);
        exit;
    }

    /** GET /api/social/connections - الحسابات المتصلة للنشر عليها */
    public function listConnections(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        try {
            $rows = $this->db->query(
                "SELECT id, platform, external_location_name AS name
                 FROM platform_connections
                 WHERE user_id = ? AND platform IN ('facebook', 'instagram', 'tiktok', 'youtube') AND status = 'connected'
                 ORDER BY platform, external_location_name",
                [$this->user['id']]
            );
            return $this->success(['connections' => $rows]);
        } catch (Exception $e) {
            Logger::error('listConnections Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر جلب الصفحات المتصلة', 500);
        }
    }

    /** GET /api/social/posts */
    public function listPosts(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $posts = (new SocialPost())->where(['user_id' => $this->user['id']], ['created_at' => 'DESC'], 50);
        return $this->success(['posts' => array_map(fn ($p) => $p->toArray(), $posts)]);
    }

    /** POST /api/social/posts */
    public function createPost(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if (!$this->validate(['content' => 'required'])) {
            return $this->error('بيانات ناقصة', 422, $this->getErrors());
        }

        try {
            $post = $this->service->createPost(
                (int) $this->user['id'],
                ['content' => $this->get('content'), 'website_id' => $this->get('website_id')],
                $this->get('targets', [])
            );
            return $this->success(['post' => $post->toArray()], 'تم حفظ المنشور', 201);
        } catch (Exception $e) {
            Logger::error('createPost Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر إنشاء المنشور', 500);
        }
    }

    /**
     * GET /api/social/calendar
     * تقويم محتوى حقيقي مبني على social_posts + social_post_targets
     * الموجودين بالفعل (عمود scheduled_at). ده بديل عن جدول
     * content_calendar المنفصل اللي كان في موديول content-studio -
     * مفيش داعي لتكراره لأن البيانات موجودة أصلًا هنا.
     */
    public function getCalendar(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        try {
            $rows = $this->db->query(
                "SELECT sp.id, sp.content, spt.scheduled_at, spt.status, spt.published_at
                 FROM social_posts sp
                 JOIN social_post_targets spt ON spt.social_post_id = sp.id
                 WHERE sp.user_id = ? AND spt.scheduled_at IS NOT NULL
                 AND spt.scheduled_at BETWEEN DATE_SUB(NOW(), INTERVAL 7 DAY) AND DATE_ADD(NOW(), INTERVAL 30 DAY)
                 ORDER BY spt.scheduled_at ASC",
                [$this->user['id']]
            );
            return $this->success(['items' => $rows]);
        } catch (Exception $e) {
            Logger::error('Social Calendar Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر تحميل التقويم', 500);
        }
    }

    /** POST /api/social/generate-caption */
    public function generateCaption(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if (!$this->validate(['topic' => 'required'])) {
            return $this->error('الموضوع مطلوب', 422);
        }

        $result = $this->service->generateCaption(
            (string) $this->get('topic'),
            (string) $this->get('platform', 'instagram'),
            (string) ($this->user['language'] ?? 'ar')
        );

        if (!$result['success']) {
            return $this->error($result['error'], 502);
        }

        return $this->success($result);
    }

    /** GET /social/connect/tiktok */
    public function connectTikTok(array $params = []): void
    {
        if (!$this->isAuthenticated()) {
            header('Location: /login?redirect=' . urlencode('/social'));
            exit;
        }

        $clientKey = env('TIKTOK_CLIENT_KEY');
        $clientSecret = env('TIKTOK_CLIENT_SECRET');
        if (!$clientKey || !$clientSecret) {
            $this->renderOAuthError('ربط TikTok لسه مش مفعّل (بيانات TIKTOK_CLIENT_KEY/SECRET ناقصة).');
            exit;
        }

        $nonce = bin2hex(random_bytes(16));
        $_SESSION['tiktok_oauth_nonce'] = $nonce;

        $redirectUri = env('TIKTOK_OAUTH_REDIRECT_URI') ?: (rtrim(defined('APP_URL') ? APP_URL : '', '/') . '/social/connect/tiktok/callback');
        $state = base64_encode(json_encode(['nonce' => $nonce], JSON_UNESCAPED_UNICODE));

        $url = 'https://www.tiktok.com/v2/auth/authorize/?'
            . http_build_query([
                'client_key'    => $clientKey,
                'response_type' => 'code',
                'scope'         => 'video.upload',
                'redirect_uri'  => $redirectUri,
                'state'         => $state,
            ]);

        header('Location: ' . $url);
        exit;
    }

    /** GET /social/connect/tiktok/callback */
    public function tikTokOAuthCallback(array $params = []): void
    {
        if (!$this->isAuthenticated()) {
            header('Location: /login');
            exit;
        }

        $error = $this->get('error');
        if ($error) {
            $this->renderOAuthError('العميل رفض الموافقة أو حصل خطأ من TikTok: ' . htmlspecialchars((string) $error, ENT_QUOTES, 'UTF-8'));
            exit;
        }

        $code = $this->get('code');
        $state = $this->get('state');
        if (!$code || !$state) {
            $this->renderOAuthError('رد غير مكتمل من TikTok');
            exit;
        }

        $decodedState = json_decode(base64_decode((string) $state), true);
        $expectedNonce = $_SESSION['tiktok_oauth_nonce'] ?? null;
        if (!$decodedState || !$expectedNonce || !hash_equals($expectedNonce, $decodedState['nonce'] ?? '')) {
            $this->renderOAuthError('انتهت صلاحية الجلسة أو محاولة غير موثوقة');
            exit;
        }

        $clientKey    = env('TIKTOK_CLIENT_KEY');
        $clientSecret = env('TIKTOK_CLIENT_SECRET');
        $redirectUri  = env('TIKTOK_OAUTH_REDIRECT_URI') ?: (rtrim(defined('APP_URL') ? APP_URL : '', '/') . '/social/connect/tiktok/callback');

        $ch = curl_init('https://open-api.tiktok.com/oauth/access_token/');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query([
                'client_key'    => $clientKey,
                'client_secret' => $clientSecret,
                'code'          => $code,
                'grant_type'    => 'authorization_code',
                'redirect_uri'  => $redirectUri,
            ]),
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $resp = curl_exec($ch);
        curl_close($ch);

        $decoded = json_decode((string) $resp, true);
        if (($decoded['error_code'] ?? 0) !== 0 || empty($decoded['data']['access_token'])) {
            $this->renderOAuthError('فشل تبادل التوكن مع TikTok: ' . htmlspecialchars($decoded['description'] ?? 'unknown error', ENT_QUOTES, 'UTF-8'));
            exit;
        }

        $data = $decoded['data'];
        $accessToken  = $data['access_token'];
        $refreshToken = $data['refresh_token'] ?? '';
        $openId       = $data['open_id'];
        $expiresIn    = $data['expires_in'] ?? 86400;

        $encryption = new Encryption();

        $existing = (new PlatformConnection())->where([
            'user_id'  => (int) $this->user['id'],
            'platform' => 'tiktok',
        ], [], 1);

        $connData = [
            'user_id'               => (int) $this->user['id'],
            'platform'              => 'tiktok',
            'status'                => 'connected',
            'access_token'          => $encryption->encrypt($accessToken),
            'refresh_token'         => $refreshToken ? $encryption->encrypt($refreshToken) : null,
            'external_location_id'  => $openId,
            'external_location_name' => 'TikTok Account',
            'token_expires_at'      => date('Y-m-d H:i:s', time() + (int) $expiresIn),
            'last_error'            => null,
        ];

        if (!empty($existing)) {
            foreach ($connData as $key => $value) {
                $existing[0]->setAttribute($key, $value);
            }
            $existing[0]->save();
        } else {
            (new PlatformConnection($connData))->save();
        }

        unset($_SESSION['tiktok_oauth_nonce']);
        header('Location: /social?connected=tiktok');
        exit;
    }

    /** GET /social/connect/youtube */
    public function connectYouTube(array $params = []): void
    {
        if (!$this->isAuthenticated()) {
            header('Location: /login?redirect=' . urlencode('/social'));
            exit;
        }

        $oauth = new GoogleOAuthClient(
            'https://www.googleapis.com/auth/youtube.upload https://www.googleapis.com/auth/youtube',
            env('YOUTUBE_OAUTH_REDIRECT_URI') ?: null
        );
        if (!$oauth->isConfigured()) {
            $this->renderOAuthError('ربط YouTube لسه مش مفعّل (GOOGLE_CLIENT_ID/SECRET أو YOUTUBE_OAUTH_REDIRECT_URI ناقصة).');
            exit;
        }

        $nonce = bin2hex(random_bytes(16));
        $_SESSION['youtube_oauth_nonce'] = $nonce;

        $state = base64_encode(json_encode(['nonce' => $nonce], JSON_UNESCAPED_UNICODE));
        header('Location: ' . $oauth->buildAuthUrl($state));
        exit;
    }

    /** GET /social/connect/youtube/callback */
    public function youTubeOAuthCallback(array $params = []): void
    {
        if (!$this->isAuthenticated()) {
            header('Location: /login');
            exit;
        }

        $error = $this->get('error');
        if ($error) {
            $this->renderOAuthError('العميل رفض الموافقة أو حصل خطأ من Google: ' . htmlspecialchars((string) $error, ENT_QUOTES, 'UTF-8'));
            exit;
        }

        $code = $this->get('code');
        $state = $this->get('state');
        if (!$code || !$state) {
            $this->renderOAuthError('رد غير مكتمل من Google');
            exit;
        }

        $decodedState = json_decode(base64_decode((string) $state), true);
        $expectedNonce = $_SESSION['youtube_oauth_nonce'] ?? null;
        if (!$decodedState || !$expectedNonce || !hash_equals($expectedNonce, $decodedState['nonce'] ?? '')) {
            $this->renderOAuthError('انتهت صلاحية الجلسة أو محاولة غير موثوقة');
            exit;
        }

        $oauth = new GoogleOAuthClient(
            'https://www.googleapis.com/auth/youtube.upload https://www.googleapis.com/auth/youtube',
            env('YOUTUBE_OAUTH_REDIRECT_URI') ?: null
        );
        $tokenResult = $oauth->exchangeCodeForTokens((string) $code);

        if (!$tokenResult['success']) {
            $this->renderOAuthError('فشل تبادل التوكن مع Google: ' . htmlspecialchars($tokenResult['error'] ?? '', ENT_QUOTES, 'UTF-8'));
            exit;
        }
        if (empty($tokenResult['refresh_token'])) {
            $this->renderOAuthError('Google ما رجعش refresh_token. افصل أي ربط سابق من إعدادات Google وحاول تاني.');
            exit;
        }

        $accessToken  = $tokenResult['access_token'];
        $refreshToken = $tokenResult['refresh_token'];

        $ch = curl_init('https://www.googleapis.com/youtube/v3/channels?part=snippet&mine=true');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $accessToken],
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $channelResp = curl_exec($ch);
        curl_close($ch);
        $channelData = json_decode((string) $channelResp, true);
        $channelId   = $channelData['items'][0]['id'] ?? null;
        $channelTitle = $channelData['items'][0]['snippet']['title'] ?? 'YouTube Channel';

        if (!$channelId) {
            $this->renderOAuthError('ما قدرناش نجيب معرف قناة YouTube.');
            exit;
        }

        $encryption = new Encryption();

        $existing = (new PlatformConnection())->where([
            'user_id'  => (int) $this->user['id'],
            'platform' => 'youtube',
        ], [], 1);

        $connData = [
            'user_id'                => (int) $this->user['id'],
            'platform'               => 'youtube',
            'status'                 => 'connected',
            'access_token'           => $encryption->encrypt($accessToken),
            'refresh_token'          => $encryption->encrypt($refreshToken),
            'external_location_id'   => $channelId,
            'external_location_name' => $channelTitle,
            'token_expires_at'       => date('Y-m-d H:i:s', time() + (int) ($tokenResult['expires_in'] ?? 3600)),
            'last_error'             => null,
        ];

        if (!empty($existing)) {
            foreach ($connData as $key => $value) {
                $existing[0]->setAttribute($key, $value);
            }
            $existing[0]->save();
        } else {
            (new PlatformConnection($connData))->save();
        }

        unset($_SESSION['youtube_oauth_nonce']);
        header('Location: /social?connected=youtube');
        exit;
    }

    private function renderOAuthError(string $message): void
    {
        echo '<div class="p-card" style="max-width:600px;margin:40px auto;text-align:center;">'
            . '<h2>⚠️ خطأ في ربط الحساب</h2>'
            . '<p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>'
            . '<a href="/social" class="p-btn primary">العودة لإدارة السوشيال ميديا</a>'
            . '</div>';
        exit;
    }
}
