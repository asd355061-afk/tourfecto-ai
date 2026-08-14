<?php
/**
 * Tourfecto - Search Console Controller
 * ربط حساب Google Search Console الخاص بكل عميل (OAuth) بكل موقع من مواقعه،
 * وجلب بيانات الأداء في نتائج البحث (clicks/impressions/ctr/position).
 * @version 1.0.0
 *
 * نفس فلسفة ReputationController::connectGoogleBusiness/googleOAuthCallback،
 * لكن هنا "الفرع" اللي العميل بيختاره هو site في Search Console مش location
 * في Business Profile، فمفيش حاجة اسمها account/location - بس site_url.
 *
 * المتطلبات قبل ما ده يشتغل فعليًا (خارج الكود، من Google Cloud Console -
 * نفس المشروع المستخدم لـ Google Business بالظبط):
 *  1) تفعيل "Google Search Console API" في المشروع.
 *  2) إضافة GOOGLE_SEARCH_CONSOLE_REDIRECT_URI كـ Authorized redirect URI
 *     في نفس OAuth Client، وإضافة قيمته في .env.
 *  3) GOOGLE_CLIENT_ID / GOOGLE_CLIENT_SECRET نفسهم المستخدمين في .env
 *     لـ Google Business (نفس المشروع، عميل OAuth واحد كفاية لأكتر من API).
 */
class SearchConsoleController extends Controller {

    private function userId(): ?int {
        return $this->user['id'] ?? null;
    }

    private function oauthClient(): GoogleOAuthClient {
        $redirectUri = defined('GOOGLE_SEARCH_CONSOLE_REDIRECT_URI')
            ? GOOGLE_SEARCH_CONSOLE_REDIRECT_URI
            : (getenv('GOOGLE_SEARCH_CONSOLE_REDIRECT_URI') ?: '');

        return new GoogleOAuthClient(GoogleOAuthClient::SCOPE_SEARCH_CONSOLE, $redirectUri ?: null);
    }

    private function renderOAuthError(string $message): void {
        $safe = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
        $body = '<div class="p-card"><div class="p-empty"><div class="p-empty-icon">⚠️</div>' . $safe
            . '<br><br><a href="/websites" class="p-btn primary">الرجوع لمواقعي</a></div></div>';
        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('_websites', 'تعذر الربط', 'Google Search Console', $body, '');
    }

    // ============================================
    // GET /search-console/connect/{website_id} - يبدأ تدفّق OAuth
    // ============================================
    public function connect(array $params = []): array {
        if (!$this->isAuthenticated()) {
            header('Location: /login?redirect=' . urlencode('/websites'));
            exit;
        }

        $websiteId = (int) ($params['website_id'] ?? 0);
        $website = (new Website())->find($websiteId);
        if (!$website || (int) $website->getAttribute('user_id') !== (int) $this->userId()) {
            $this->renderOAuthError('الموقع غير موجود أو ملكش صلاحية عليه');
            exit;
        }

        $oauth = $this->oauthClient();
        if (!$oauth->isConfigured()) {
            $this->renderOAuthError('ربط Search Console لسه مش مفعّل من إدارة النظام (بيانات OAuth ناقصة في إعدادات السيرفر - راجع GOOGLE_SEARCH_CONSOLE_REDIRECT_URI في .env).');
            exit;
        }

        $nonce = bin2hex(random_bytes(16));
        $_SESSION['gsc_oauth_nonce'] = $nonce;

        $state = base64_encode(json_encode(['nonce' => $nonce, 'website_id' => $websiteId], JSON_UNESCAPED_UNICODE));

        header('Location: ' . $oauth->buildAuthUrl($state));
        exit;
    }

    // ============================================
    // GET /search-console/callback - Google بيرجّع العميل هنا
    // ============================================
    public function callback(array $params = []): array {
        if (!$this->isAuthenticated()) {
            header('Location: /login');
            exit;
        }

        $error = $this->get('error');
        if ($error) {
            $this->renderOAuthError('العميل رفض الموافقة أو حصل خطأ من Google: ' . $error);
            exit;
        }

        $code = $this->get('code');
        $state = $this->get('state');
        if (!$code || !$state) {
            $this->renderOAuthError('رد غير مكتمل من Google');
            exit;
        }

        $decodedState = json_decode(base64_decode($state), true);
        $expectedNonce = $_SESSION['gsc_oauth_nonce'] ?? null;

        if (!$decodedState || !$expectedNonce || !hash_equals($expectedNonce, $decodedState['nonce'] ?? '')) {
            $this->renderOAuthError('انتهت صلاحية الجلسة أو محاولة غير موثوقة، جرّب تربط الحساب تاني');
            exit;
        }

        $websiteId = (int) ($decodedState['website_id'] ?? 0);

        $oauth = $this->oauthClient();
        $tokenResult = $oauth->exchangeCodeForTokens($code);

        if (!$tokenResult['success']) {
            $this->renderOAuthError('فشل تبادل التوكن مع Google: ' . ($tokenResult['error'] ?? ''));
            exit;
        }

        // بنخزن التوكنات مؤقتًا في الجلسة لحد ما العميل يختار الـ site بتاعه
        $_SESSION['gsc_oauth_temp'] = [
            'website_id' => $websiteId,
            'access_token' => $tokenResult['access_token'],
            'refresh_token' => $tokenResult['refresh_token'] ?? null,
            'expires_in' => $tokenResult['expires_in'],
        ];
        unset($_SESSION['gsc_oauth_nonce']);

        header('Location: /search-console/choose');
        exit;
    }

    // ============================================
    // GET /search-console/choose - يختار العميل site
    // ============================================
    public function showSitePicker(array $params = []): array {
        if (!$this->isAuthenticated()) {
            header('Location: /login');
            exit;
        }

        $temp = $_SESSION['gsc_oauth_temp'] ?? null;
        if (!$temp) {
            header('Location: /websites');
            exit;
        }

        $api = new GoogleSearchConsoleAPI($temp['access_token']);
        $sitesResult = $api->listSites();

        if (!$sitesResult['success'] || empty($sitesResult['sites'])) {
            $this->renderOAuthError('مفيش مواقع مؤكدة الملكية في Search Console مرتبطة بحساب Google ده. تأكد إنك مسجّل دخول بنفس الحساب اللي مضيف بيه الموقع في Search Console.<br><br>تفاصيل تقنية: ' . htmlspecialchars($sitesResult['error'] ?? '', ENT_QUOTES, 'UTF-8'));
            exit;
        }

        $optionsJson = json_encode($sitesResult['sites'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $body = <<<'HTML'
        <div class="p-card">
            <div class="p-card-head"><h3>اختر موقعك على Search Console</h3><span class="p-card-sub">لقينا أكتر من موقع مرتبط بحسابك</span></div>
            <div id="siteOptions"></div>
        </div>
HTML;

        $script = <<<'JS'
(function () {
    const P = window.Panel;
    const esc = P.esc, fetchJSON = P.fetchJSON, toast = P.toast;
    const sites = __OPTIONS_JSON__;

    document.getElementById('siteOptions').innerHTML = sites.map((s, i) => `
        <div class="p-card" style="background:var(--panel-bg,#f7f8fa);margin-bottom:10px;display:flex;justify-content:space-between;align-items:center;">
            <div>
                <strong style="direction:ltr;display:inline-block;">${esc(s.site_url)}</strong><br>
                <span class="p-cell-muted">${esc(s.permission_level)}</span>
            </div>
            <button class="p-btn primary" onclick="selectSite(${i})">اختيار</button>
        </div>`).join('');

    window.selectSite = async function (i) {
        const s = sites[i];
        const res = await fetchJSON('/api/search-console/finalize', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ site_url: s.site_url }),
        });
        if (res.success) {
            toast('تم ربط Google Search Console بنجاح ✔', 'success');
            window.location.href = '/websites';
        } else {
            toast(res.error || 'تعذر إتمام الربط', 'error');
        }
    };
})();
JS;
        $script = str_replace('__OPTIONS_JSON__', $optionsJson, $script);

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('_websites', 'اختيار الموقع', 'Google Search Console', $body, $script);
        exit;
    }

    // ============================================
    // POST /api/search-console/finalize
    // ============================================
    public function finalize(array $params = []): array {
        if (!$this->isAuthenticated()) {
            return $this->error('غير مسجل دخول', 401);
        }

        $temp = $_SESSION['gsc_oauth_temp'] ?? null;
        if (!$temp) {
            return $this->error('انتهت جلسة الربط، جرّب تاني', 422);
        }

        $siteUrl = $this->get('site_url');
        if (!$siteUrl) {
            return $this->error('اختيار الموقع مطلوب', 422);
        }

        try {
            $encryption = new Encryption();
            $existing = (new PlatformConnection())->where([
                'website_id' => $temp['website_id'],
                'platform' => 'google_search_console',
            ], [], 1);

            $data = [
                'website_id' => $temp['website_id'],
                'user_id' => $this->userId(),
                'platform' => 'google_search_console',
                'access_token' => $encryption->encrypt($temp['access_token']),
                'refresh_token' => $temp['refresh_token'] ? $encryption->encrypt($temp['refresh_token']) : null,
                'token_expires_at' => date('Y-m-d H:i:s', time() + (int) $temp['expires_in']),
                'external_account_id' => null,
                'external_location_id' => $siteUrl,
                'external_location_name' => $siteUrl,
                'status' => 'connected',
                'last_error' => null,
            ];

            $connection = new PlatformConnection($data);
            if (!empty($existing)) {
                $connection->setAttribute('id', $existing[0]->getAttribute('id'));
                $connection->save();
            } else {
                $connection->save();
            }

            unset($_SESSION['gsc_oauth_temp']);

            $this->log('Google Search Console Connected', ['website_id' => $temp['website_id'], 'site_url' => $siteUrl]);

            return $this->success([], 'تم ربط Google Search Console بنجاح');
        } catch (Exception $e) {
            Logger::error('Finalize Search Console Connection Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر حفظ الربط', 500);
        }
    }

    // ============================================
    // POST /api/search-console/disconnect/{website_id}
    // ============================================
    public function disconnect(array $params = []): array {
        if (!$this->isAuthenticated()) {
            return $this->error('غير مسجل دخول', 401);
        }

        $websiteId = (int) ($params['website_id'] ?? 0);
        $website = (new Website())->find($websiteId);
        if (!$website || (int) $website->getAttribute('user_id') !== (int) $this->userId()) {
            return $this->error('الموقع غير موجود', 404);
        }

        $connections = (new PlatformConnection())->where([
            'website_id' => $websiteId,
            'platform' => 'google_search_console',
        ], [], 1);

        if (empty($connections)) {
            return $this->error('مفيش اتصال Search Console لهذا الموقع', 404);
        }

        $connections[0]->setAttribute('status', 'disconnected');
        $connections[0]->setAttribute('access_token', null);
        $connections[0]->setAttribute('refresh_token', null);
        $connections[0]->save();

        $this->log('Google Search Console Disconnected', ['website_id' => $websiteId]);

        return $this->success([], 'تم فصل Search Console');
    }

    // ============================================
    // GET /api/search-console/stats/{website_id} - ملخص آخر 28 يوم
    // ============================================
    public function stats(array $params = []): array {
        if (!$this->isAuthenticated()) {
            return $this->error('غير مسجل دخول', 401);
        }

        $websiteId = (int) ($params['website_id'] ?? 0);
        $website = (new Website())->find($websiteId);
        if (!$website || (int) $website->getAttribute('user_id') !== (int) $this->userId()) {
            return $this->error('الموقع غير موجود', 404);
        }

        $connections = (new PlatformConnection())->where([
            'website_id' => $websiteId,
            'platform' => 'google_search_console',
            'status' => 'connected',
        ], [], 1);

        if (empty($connections)) {
            return $this->error('الموقع ده لسه مش متربط بـ Search Console', 404);
        }

        $connection = $connections[0];
        $encryption = new Encryption();

        try {
            $accessToken = $encryption->decrypt($connection->getAttribute('access_token'));

            // لو التوكن قرب ينتهي، نجدده الأول بالـ refresh_token المحفوظ
            if ((new PlatformConnection($connection->toArray()))->isTokenExpired() && $connection->getAttribute('refresh_token')) {
                $refreshToken = $encryption->decrypt($connection->getAttribute('refresh_token'));
                $refreshed = $this->oauthClient()->refreshAccessToken($refreshToken);

                if ($refreshed['success']) {
                    $accessToken = $refreshed['access_token'];
                    $connection->setAttribute('access_token', $encryption->encrypt($accessToken));
                    $connection->setAttribute('token_expires_at', date('Y-m-d H:i:s', time() + (int) $refreshed['expires_in']));
                    $connection->save();
                }
            }

            $api = new GoogleSearchConsoleAPI($accessToken);
            $siteUrl = (string) $connection->getAttribute('external_location_id');

            $summary = $api->getSummary($siteUrl);
            if (!$summary['success']) {
                $connection->setAttribute('status', 'error');
                $connection->setAttribute('last_error', $summary['error'] ?? 'Unknown error');
                $connection->save();
                return $this->error('تعذر جلب بيانات Search Console: ' . ($summary['error'] ?? ''), 502);
            }

            $topQueries = $api->getSearchAnalytics($siteUrl, $summary['summary']['start_date'], $summary['summary']['end_date'], ['query'], 10);

            $connection->setAttribute('last_synced_at', date('Y-m-d H:i:s'));
            $connection->save();

            return $this->success([
                'site_url' => $siteUrl,
                'summary' => $summary['summary'],
                'top_queries' => $topQueries['success'] ? $topQueries['rows'] : [],
            ]);
        } catch (Exception $e) {
            Logger::error('Search Console Stats Error', ['website_id' => $websiteId, 'message' => $e->getMessage()]);
            return $this->error('تعذر جلب بيانات Search Console', 500);
        }
    }
}
