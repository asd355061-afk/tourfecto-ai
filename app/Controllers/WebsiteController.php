<?php
/**
 * Tourfecto - Website Controller
 * إدارة مواقع المستخدم (CRUD) + التحقق الحقيقي من الملكية
 * @version 1.1.0
 *
 * تصحيح (2026-07-12): كان verify() بيوافق على أي طلب فورًا من غير أي فحص
 * حقيقي (rubber-stamp) — أي حد يقدر يدّعي ملكية أي موقع. دلوقتي بنعمل تحقق
 * فعلي بطريقتين قياسيتين (زي Google Search Console بالظبط):
 *   1) meta tag في <head> الموقع المستهدف.
 *   2) سجل DNS TXT على الدومين.
 * التوكن نفسه HMAC deterministic (مش عمود جديد في قاعدة البيانات) عشان منحتاجش
 * migration على قاعدة بيانات حية معندناش وصول ليها مباشر.
 */

class WebsiteController extends Controller {

    private function userId(): ?int {
        return $_SESSION['user_id'] ?? null;
    }

    /**
     * هل الطلب الحالي API (JSON) ولا صفحة ويب عادية؟
     * نفس فكرة AuthMiddleware::isWebPageRequest لكن هنا بنستخدمها عشان
     * index()/show() يقدروا يخدموا الاتنين (الصفحة و الـ API) من غير تكرار.
     */
    private function isApiRequest(): bool {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        return strpos($path, '/api/') === 0;
    }

    // ============================================
    // توليد والتحقق من توكن الملكية
    // ============================================

    private function verificationSecret(): string {
        return (defined('ENCRYPTION_KEY') && ENCRYPTION_KEY) ? ENCRYPTION_KEY : 'tourfecto-fallback-verification-secret';
    }

    private function verificationToken(int $userId, int $websiteId): string {
        return substr(hash_hmac('sha256', 'tourfecto-site-verify:' . $userId . ':' . $websiteId, $this->verificationSecret()), 0, 32);
    }

    private function normalizeUrl(string $url): string {
        if (!preg_match('#^https?://#i', $url)) {
            $url = 'https://' . ltrim($url, '/');
        }
        return $url;
    }

    private function verificationInstructions(Website $website): array {
        $userId = (int) $website->getAttribute('user_id');
        $id = (int) $website->getAttribute('id');
        $token = $this->verificationToken($userId, $id);
        $url = $this->normalizeUrl((string) $website->getAttribute('main_url'));
        $host = parse_url($url, PHP_URL_HOST) ?: $website->getAttribute('main_url');

        return [
            'token' => $token,
            'meta_tag' => '<meta name="tourfecto-verification" content="' . $token . '">',
            'dns_record' => [
                'type' => 'TXT',
                'host' => $host,
                'value' => 'tourfecto-verify=' . $token,
            ],
        ];
    }

    /**
     * حماية SSRF: امنع الفحص على IP خاص/داخلي/loopback عشان محدش يستخدم
     * فورم "أضف موقع" عشان يخلي السيرفر يعمل طلبات لشبكته الداخلية.
     */
    private function isPubliclyRoutableHost(string $host): bool {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ip = $host;
        } else {
            $resolved = gethostbyname($host);
            // gethostbyname() بترجع الـ host نفسه لو فشل الحل، فده يبقى معناه فشل
            if ($resolved === $host) {
                return false;
            }
            $ip = $resolved;
        }

        return (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }

    private function checkMetaTag(string $url, string $token): bool {
        $host = parse_url($url, PHP_URL_HOST);
        if (!$host || !$this->isPubliclyRoutableHost($host)) {
            return false;
        }

        try {
            $ch = curl_init();
            $downloaded = '';
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_USERAGENT => 'TourfectoVerificationBot/1.0 (+https://tourfecto.com)',
                // نوقف التحميل بعد أول 300 كيلوبايت كفاية لوسم <meta> في الـ <head>
                CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$downloaded) {
                    $downloaded .= $chunk;
                    return strlen($downloaded) > 300000 ? 0 : strlen($chunk);
                },
            ]);
            curl_exec($ch);
            $err = curl_errno($ch);
            curl_close($ch);

            if ($err || !$downloaded) {
                return false;
            }

            $pattern = '/<meta\s+[^>]*name=["\']tourfecto-verification["\'][^>]*content=["\']' . preg_quote($token, '/') . '["\'][^>]*>/i';
            return (bool) preg_match($pattern, $downloaded);
        } catch (Throwable $e) {
            Logger::warning('Website meta-tag verification failed', ['url' => $url, 'error' => $e->getMessage()]);
            return false;
        }
    }

    private function checkDnsTxt(string $host, string $token): bool {
        if (!$this->isPubliclyRoutableHost($host)) {
            return false;
        }

        try {
            $records = @dns_get_record($host, DNS_TXT);
            if (!$records) {
                return false;
            }

            $needle = 'tourfecto-verify=' . $token;
            foreach ($records as $r) {
                if (isset($r['txt']) && trim($r['txt']) === $needle) {
                    return true;
                }
                if (isset($r['entries']) && is_array($r['entries'])) {
                    foreach ($r['entries'] as $entry) {
                        if (trim($entry) === $needle) {
                            return true;
                        }
                    }
                }
            }
            return false;
        } catch (Throwable $e) {
            Logger::warning('Website DNS-TXT verification failed', ['host' => $host, 'error' => $e->getMessage()]);
            return false;
        }
    }

    // ============================================
    // GET /websites و GET /api/websites
    // ============================================

    public function index(array $params = []): array {
        $userId = $this->userId();
        if (!$userId) {
            if ($this->isApiRequest()) {
                return $this->error('غير مسجل دخول', 401);
            }
            header('Location: /login?redirect=' . urlencode('/websites'));
            exit;
        }

        $model = new Website();
        $sites = $model->where(['user_id' => $userId], ['created_at' => 'DESC']);

        // تصحيح: where() بترجع كائنات Website (Model)، والكلاس ده مالوش أي
        // public properties ومش عامل implements JsonSerializable، فلو اتبعت
        // زي ما هي في JSON response كانت هتتحول لـ {} فاضية بدل بيانات الموقع
        // الفعلية. لازم نعمل toArray() على كل واحد قبل الإرجاع.
        $sitesArray = array_map(function ($site) {
            $data = $site->toArray();
            $data['is_verified'] = (int) ($data['is_verified'] ?? 0);
            return $data;
        }, $sites);

        if ($this->isApiRequest()) {
            return $this->success(['websites' => $sitesArray]);
        }

        $this->renderIndexPage($sitesArray);
        exit;
    }

    private function renderIndexPage(array $sites): void {
        $tAddNew = $this->tr('websites.add_new');
        $tUrlLabel = $this->tr('websites.url_label');
        $tCompanyLabel = $this->tr('websites.company_label');
        $tAddButton = $this->tr('websites.add_button');
        $tYourSites = $this->tr('websites.your_sites');
        $tColSite = $this->tr('websites.col.site');
        $tColCompany = $this->tr('websites.col.company');
        $tColStatus = $this->tr('websites.col.status');
        $tColDate = $this->tr('websites.col.date');

        $body = <<<HTML
        <div class="p-card">
            <div class="p-card-head"><h3>+ {$tAddNew}</h3></div>
            <form id="addWebsiteForm">
                <div class="p-grid cols-2">
                    <div class="form-group">
                        <label class="form-label" for="main_url">{$tUrlLabel} *</label>
                        <input type="text" id="main_url" class="form-control" placeholder="https://example.com" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="company_name">{$tCompanyLabel}</label>
                        <input type="text" id="company_name" class="form-control">
                    </div>
                </div>
                <div id="addWebsiteAlert" class="alert alert-danger" style="display:none;"></div>
                <button type="submit" class="p-btn primary">{$tAddButton}</button>
            </form>
        </div>
        <div class="p-card no-pad" style="margin-top:16px;">
            <div class="p-card-head" style="padding:18px 20px 0;"><h3>{$tYourSites}</h3></div>
            <div class="p-table-scroll"><table class="p-table" id="websitesTable">
                <thead><tr><th>{$tColSite}</th><th>{$tColCompany}</th><th>{$tColStatus}</th><th>{$tColDate}</th><th></th></tr></thead>
                <tbody></tbody>
            </table></div>
        </div>
HTML;

        $sitesJson = json_encode($sites, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $tNoSites = $this->trJs('websites.no_sites');
        $tVerified = $this->trJs('websites.verified');
        $tUnverified = $this->trJs('websites.unverified');
        $tManage = $this->trJs('websites.manage');
        $tAddFailed = $this->trJs('websites.add_failed');
        $tAddedSuccess = $this->trJs('websites.added_success');

        $script = <<<JS
(function () {
    const P = window.Panel;
    const esc = P.esc, fetchJSON = P.fetchJSON, toast = P.toast, formatDate = P.formatDate;
    let sites = __SITES_JSON__;

    function renderRows() {
        const tbody = document.querySelector('#websitesTable tbody');
        if (!sites.length) {
            tbody.innerHTML = '<tr><td colspan="5" class="p-cell-muted text-center">' + {$tNoSites} + '</td></tr>';
            return;
        }
        tbody.innerHTML = sites.map(w => `
            <tr>
                <td>\${esc(w.main_url || '-')}</td>
                <td>\${esc(w.company_name || '-')}</td>
                <td>\${w.is_verified == 1 ? '<span class="pill green">✔ ' + {$tVerified} + '</span>' : '<span class="pill">' + {$tUnverified} + '</span>'}</td>
                <td class="p-cell-muted">\${formatDate(w.created_at)}</td>
                <td><a href="/websites/\${w.id}" class="p-btn outline xs">{$tManage}</a></td>
            </tr>`).join('');
    }
    renderRows();

    document.getElementById('addWebsiteForm').addEventListener('submit', async function (e) {
        e.preventDefault();
        const alertBox = document.getElementById('addWebsiteAlert');
        alertBox.style.display = 'none';

        const res = await fetchJSON('/api/websites', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                main_url: document.getElementById('main_url').value.trim(),
                company_name: document.getElementById('company_name').value.trim(),
            }),
        });

        if (!res.success) {
            alertBox.textContent = res.error || {$tAddFailed};
            alertBox.style.display = 'block';
            return;
        }

        toast({$tAddedSuccess}, 'success');
        window.location.href = '/websites/' + res.data.website.id;
    });
})();
JS;
        $script = str_replace('__SITES_JSON__', $sitesJson, $script);

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('_websites', $this->tr('sidebar.websites'), $this->tr('websites.page_subtitle'), $body, $script);
    }

    /** GET /websites/create -> بدل صفحة منفصلة، الفورم بقى جزء من /websites */
    public function create(array $params = []): array {
        if ($this->isApiRequest()) {
            return $this->success(['form' => 'create_website']);
        }
        header('Location: /websites');
        exit;
    }

    /** POST /websites/store و POST /api/websites */
    public function store(array $params = []): array {
        $userId = $this->userId();
        if (!$userId) {
            return $this->error('غير مسجل دخول', 401);
        }

        if (!$this->validate(['main_url' => 'required'])) {
            return $this->error('بيانات غير صحيحة', 422, $this->getErrors());
        }

        try {
            $website = new Website([
                'user_id' => $userId,
                'main_url' => $this->get('main_url'),
                'company_name' => $this->get('company_name'),
                'industry' => $this->get('industry', 'tourism'),
                'target_language' => $this->get('target_language', 'ar'),
                'target_country' => $this->get('target_country'),
                'meta_description' => $this->get('meta_description'),
                'competitor_1_url' => $this->get('competitor_1_url'),
                'competitor_2_url' => $this->get('competitor_2_url'),
                'competitor_3_url' => $this->get('competitor_3_url'),
                'is_verified' => 0,
            ]);

            $id = $website->save();
            if (!$id) {
                return $this->error('تعذر إضافة الموقع', 500);
            }

            $saved = $website->find($id);

            return $this->success([
                'website' => $saved->toArray(),
                'verification' => $this->verificationInstructions($saved),
            ], 'تم إضافة الموقع بنجاح، وتفعيله محتاج تأكيد الملكية', 201);

        } catch (Exception $e) {
            Logger::error('Website Store Error', ['message' => $e->getMessage()]);
            $debugMsg = (defined('APP_DEBUG') && APP_DEBUG)
                ? 'تعذر إضافة الموقع: ' . $e->getMessage()
                : 'تعذر إضافة الموقع';
            return $this->error($debugMsg, 500);
        }
    }

    // ============================================
    // GET /websites/{id} و GET /api/websites/{id}
    // ============================================

    public function show(array $params): array {
        $userId = $this->userId();
        if (!$userId) {
            if ($this->isApiRequest()) {
                return $this->error('غير مسجل دخول', 401);
            }
            header('Location: /login?redirect=' . urlencode('/websites/' . ($params['id'] ?? '')));
            exit;
        }

        $model = new Website();
        $website = $model->find((int) ($params['id'] ?? 0));

        if (!$website || (int) $website->getAttribute('user_id') !== (int) $userId) {
            if ($this->isApiRequest()) {
                return $this->error('الموقع غير موجود', 404);
            }
            header('Content-Type: text/html; charset=utf-8');
            $tNotFoundTitle = $this->tr('websites.not_found_title');
            $tNotFoundBody = $this->tr('websites.not_found_body');
            $tBackToSites = $this->tr('websites.back_to_sites');
            echo $this->renderPanelPage('_websites', $tNotFoundTitle, '', '<div class="p-card"><div class="p-empty"><div class="p-empty-icon">🌐</div>' . $tNotFoundBody . ' <a href="/websites">' . $tBackToSites . '</a></div></div>', '');
            exit;
        }

        if ($this->isApiRequest()) {
            return $this->success([
                'website' => $website->toArray(),
                'verification' => $this->verificationInstructions($website),
            ]);
        }

        $this->renderShowPage($website);
        exit;
    }

    private function renderShowPage(Website $website): void {
        $id = (int) $website->getAttribute('id');
        $mainUrl = htmlspecialchars((string) $website->getAttribute('main_url'), ENT_QUOTES, 'UTF-8');
        $isVerified = (int) $website->getAttribute('is_verified') === 1;
        $instructions = $this->verificationInstructions($website);
        $metaTagSafe = htmlspecialchars($instructions['meta_tag'], ENT_QUOTES, 'UTF-8');
        $dnsHostSafe = htmlspecialchars($instructions['dns_record']['host'], ENT_QUOTES, 'UTF-8');
        $dnsValueSafe = htmlspecialchars($instructions['dns_record']['value'], ENT_QUOTES, 'UTF-8');

        $tVerifiedMsg = $this->tr('websites.verified_msg');
        $tNotVerifiedWarning = $this->tr('websites.not_verified_warning');
        $tChooseMethod = $this->tr('websites.choose_method');
        $tMetaTagTitle = $this->tr('websites.meta_tag_title');
        $tMetaTagDesc = $this->tr('websites.meta_tag_desc');
        $tDnsTitle = $this->tr('websites.dns_title');
        $tDnsDesc = $this->tr('websites.dns_desc');
        $tVerifyNow = $this->tr('websites.verify_now');

        $verifyBlock = $isVerified
            ? '<div class="alert alert-success">✔ ' . $tVerifiedMsg . '</div>'
            : <<<HTML
                <div class="alert alert-warning">⚠️ {$tNotVerifiedWarning}</div>
                <p class="p-cell-muted">{$tChooseMethod}</p>
                <div class="p-grid cols-2">
                    <div class="p-card" style="background:var(--panel-bg,#f7f8fa);">
                        <div class="p-card-head"><h3>1) {$tMetaTagTitle}</h3></div>
                        <p class="p-cell-muted">{$tMetaTagDesc}</p>
                        <code style="display:block;background:#1e1e2e;color:#a6e3a1;padding:10px;border-radius:8px;overflow-x:auto;direction:ltr;text-align:left;">{$metaTagSafe}</code>
                    </div>
                    <div class="p-card" style="background:var(--panel-bg,#f7f8fa);">
                        <div class="p-card-head"><h3>2) {$tDnsTitle}</h3></div>
                        <p class="p-cell-muted">{$tDnsDesc}</p>
                        <div class="p-kv"><span class="k">Host</span><span class="v" style="direction:ltr;">{$dnsHostSafe}</span></div>
                        <div class="p-kv"><span class="k">Value</span><span class="v" style="direction:ltr;">{$dnsValueSafe}</span></div>
                    </div>
                </div>
                <button class="p-btn primary" id="verifyBtn" onclick="verifyNow()" style="margin-top:14px;">🔍 {$tVerifyNow}</button>
                <div id="verifyAlert" class="alert alert-danger" style="display:none;margin-top:10px;"></div>
HTML;

        $tSiteSettingsSub = $this->tr('websites.site_settings_sub');
        $tDefaultCompetitors = $this->tr('websites.default_competitors');
        $tDefaultCompetitorsSub = $this->tr('websites.default_competitors_sub');
        $tCompetitorLabel = $this->tr('websites.competitor_label');
        $tCompetitor1 = $tCompetitorLabel . ' 1';
        $tCompetitor2 = $tCompetitorLabel . ' 2';
        $tCompetitor3 = $tCompetitorLabel . ' 3';
        $tSaveCompetitors = $this->tr('websites.save_competitors');
        $tGscSub = $this->tr('websites.gsc_sub');
        $tLoading = $this->tr('common.loading');
        $tDeleteWebsite = $this->tr('websites.delete_website');

        $body = <<<HTML
        <div class="p-card">
            <div class="p-card-head"><h3>{$mainUrl}</h3><span class="p-card-sub">{$tSiteSettingsSub}</span></div>
            {$verifyBlock}
        </div>

        <div class="p-card" style="margin-top:16px;">
            <div class="p-card-head"><h3>{$tDefaultCompetitors}</h3><span class="p-card-sub">{$tDefaultCompetitorsSub}</span></div>
            <div class="form-group"><input type="url" id="c1" class="form-control" placeholder="{$tCompetitor1}" style="margin-bottom:8px;"></div>
            <div class="form-group"><input type="url" id="c2" class="form-control" placeholder="{$tCompetitor2}" style="margin-bottom:8px;"></div>
            <div class="form-group"><input type="url" id="c3" class="form-control" placeholder="{$tCompetitor3}"></div>
            <button class="p-btn outline" onclick="saveCompetitors()" style="margin-top:10px;">{$tSaveCompetitors}</button>
        </div>

        <div class="p-card" style="margin-top:16px;">
            <div class="p-card-head"><h3>🔍 Google Search Console</h3><span class="p-card-sub">{$tGscSub}</span></div>
            <div id="gscContent"><div class="p-loading-row">{$tLoading}</div></div>
        </div>

        <div class="p-card" style="margin-top:16px;">
            <button class="p-btn danger" onclick="deleteWebsite()">🗑 {$tDeleteWebsite}</button>
        </div>
HTML;

        $tSavedMsg = $this->trJs('common.saved');
        $tSaveFailedMsg = $this->trJs('common.save_failed');
        $tDeleteConfirm = $this->trJs('websites.delete_confirm');
        $tDeletedMsg = $this->trJs('common.deleted');
        $tDeleteFailedMsg = $this->trJs('common.delete_failed');
        $tVerifying = $this->trJs('websites.verifying');
        $tVerifiedSuccess = $this->trJs('websites.verified_success');
        $tVerifyNowBtn = $this->trJs('websites.verify_now_btn');
        $tVerifyFailed = $this->trJs('websites.verify_failed');
        $tNotConnectedGsc = $this->trJs('websites.not_connected_gsc');
        $tConnectAccount = $this->trJs('websites.connect_account');
        $tClicks = $this->trJs('websites.clicks');
        $tImpressions = $this->trJs('websites.impressions');
        $tAvgPosition = $this->trJs('websites.avg_position');
        $tSearchQuery = $this->trJs('websites.search_query');
        $tRank = $this->trJs('websites.rank');
        $tNoDataYet = $this->trJs('websites.no_data_yet');
        $tDisconnectGsc = $this->trJs('websites.disconnect_gsc');
        $tDisconnectGscConfirm = $this->trJs('websites.disconnect_gsc_confirm');
        $tDisconnected = $this->trJs('common.disconnected');
        $tDisconnectFailed = $this->trJs('common.disconnect_failed');

        $script = <<<JS
(function () {
    const P = window.Panel;
    const esc = P.esc, fetchJSON = P.fetchJSON, toast = P.toast;
    const websiteId = __WEBSITE_ID__;

    async function loadCompetitors() {
        const res = await fetchJSON('/api/websites/' + websiteId);
        if (res.success) {
            const w = res.data.website;
            document.getElementById('c1').value = w.competitor_1_url || '';
            document.getElementById('c2').value = w.competitor_2_url || '';
            document.getElementById('c3').value = w.competitor_3_url || '';
        }
    }

    window.saveCompetitors = async function () {
        const res = await fetchJSON('/api/websites/' + websiteId + '/competitors', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                competitor_1_url: document.getElementById('c1').value.trim(),
                competitor_2_url: document.getElementById('c2').value.trim(),
                competitor_3_url: document.getElementById('c3').value.trim(),
            }),
        });
        if (res.success) toast({$tSavedMsg}, 'success');
        else toast(res.error || {$tSaveFailedMsg}, 'error');
    };

    window.deleteWebsite = async function () {
        if (!confirm({$tDeleteConfirm})) return;
        const res = await fetchJSON('/api/websites/' + websiteId, { method: 'DELETE' });
        if (res.success) { toast({$tDeletedMsg}, 'success'); window.location.href = '/websites'; }
        else toast(res.error || {$tDeleteFailedMsg}, 'error');
    };

    window.verifyNow = async function () {
        const btn = document.getElementById('verifyBtn');
        const alertBox = document.getElementById('verifyAlert');
        alertBox.style.display = 'none';
        btn.disabled = true;
        btn.textContent = {$tVerifying};

        const res = await fetchJSON('/api/websites/' + websiteId + '/verify', { method: 'POST' });

        if (res.success) {
            toast({$tVerifiedSuccess}, 'success');
            window.location.reload();
        } else {
            btn.disabled = false;
            btn.textContent = {$tVerifyNowBtn};
            alertBox.textContent = res.error || {$tVerifyFailed};
            alertBox.style.display = 'block';
        }
    };

    async function loadSearchConsole() {
        const box = document.getElementById('gscContent');
        const res = await fetchJSON('/api/search-console/stats/' + websiteId);
        const L = {
            notConnected: {$tNotConnectedGsc}, connect: {$tConnectAccount},
            clicks: {$tClicks}, impressions: {$tImpressions}, avgPosition: {$tAvgPosition},
            searchQuery: {$tSearchQuery}, rank: {$tRank}, noData: {$tNoDataYet}, disconnect: {$tDisconnectGsc},
        };

        if (!res.success) {
            // لسه مش متربط (أو حصل خطأ) - نعرض زرار الربط
            box.innerHTML = `
                <p class="p-cell-muted">\${L.notConnected}</p>
                <a href="/search-console/connect/\${websiteId}" class="p-btn primary xs">🔗 \${L.connect}</a>`;
            return;
        }

        const d = res.data;
        const s = d.summary;
        const rows = (d.top_queries || []).map(q => `
            <tr>
                <td style="direction:ltr;text-align:left;">\${esc(q.query || '')}</td>
                <td>\${q.clicks}</td>
                <td>\${q.impressions}</td>
                <td>\${q.ctr}%</td>
                <td>\${q.position}</td>
            </tr>`).join('');

        box.innerHTML = `
            <div class="p-cell-muted" style="direction:ltr;text-align:left;margin-bottom:10px;">\${esc(d.site_url)}</div>
            <div class="p-grid cols-4" style="margin-bottom:14px;">
                <div class="p-card stat-tile"><div class="stat-info"><div class="stat-value">\${s.clicks}</div><div class="stat-label">\${L.clicks}</div></div></div>
                <div class="p-card stat-tile"><div class="stat-info"><div class="stat-value">\${s.impressions}</div><div class="stat-label">\${L.impressions}</div></div></div>
                <div class="p-card stat-tile"><div class="stat-info"><div class="stat-value">\${s.ctr}%</div><div class="stat-label">CTR</div></div></div>
                <div class="p-card stat-tile"><div class="stat-info"><div class="stat-value">\${s.avg_position}</div><div class="stat-label">\${L.avgPosition}</div></div></div>
            </div>
            <div class="p-table-scroll">
            <table class="p-table" style="min-width:520px;">
                <thead><tr><th style="direction:ltr;text-align:left;">\${L.searchQuery}</th><th>\${L.clicks}</th><th>\${L.impressions}</th><th>CTR</th><th>\${L.rank}</th></tr></thead>
                <tbody>\${rows || '<tr><td colspan="5" class="p-cell-muted">' + {$tNoDataYet} + '</td></tr>'}</tbody>
            </table>
            </div>
            <button class="p-btn outline xs" style="margin-top:10px;" onclick="disconnectGSC()">\${L.disconnect}</button>`;
    }

    window.disconnectGSC = async function () {
        if (!confirm({$tDisconnectGscConfirm})) return;
        const res = await fetchJSON('/api/search-console/disconnect/' + websiteId, { method: 'POST' });
        if (res.success) { toast({$tDisconnected}, 'success'); loadSearchConsole(); }
        else toast(res.error || {$tDisconnectFailed}, 'error');
    };

    loadCompetitors();
    loadSearchConsole();
})();
JS;
        $script = str_replace('__WEBSITE_ID__', (string) $id, $script);

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('_websites', $this->tr('websites.manage_site'), $mainUrl, $body, $script);
    }

    /** GET /websites/{id}/edit */
    public function edit(array $params): array {
        return $this->show($params);
    }

    /** PUT /websites/{id} و PUT /api/websites/{id} */
    public function update(array $params): array {
        $userId = $this->userId();
        if (!$userId) {
            return $this->error('غير مسجل دخول', 401);
        }

        $model = new Website();
        $website = $model->find((int) ($params['id'] ?? 0));

        if (!$website || (int) $website->getAttribute('user_id') !== (int) $userId) {
            return $this->error('الموقع غير موجود', 404);
        }

        foreach ([
            'main_url', 'company_name', 'industry', 'target_language', 'target_country',
            'meta_description', 'competitor_1_url', 'competitor_2_url', 'competitor_3_url'
        ] as $field) {
            if ($this->has($field)) {
                // تصحيح: لو المستخدم غيّر main_url، لازم يعيد التحقق من الملكية
                // من جديد (رابط جديد = ملكية لازم تتأكد تاني)، وإلا هيفضل الموقع
                // "موثّق" على رابط مختلف تمامًا عن اللي اتحقق منه فعليًا.
                if ($field === 'main_url' && $this->get($field) !== $website->getAttribute('main_url')) {
                    $website->setAttribute('is_verified', 0);
                }
                $website->setAttribute($field, $this->get($field));
            }
        }

        if ($website->save() === false) {
            return $this->error('تعذر تحديث الموقع', 500);
        }

        return $this->success(['website' => $website->toArray()], 'تم تحديث الموقع');
    }

    /** DELETE /websites/{id} و DELETE /api/websites/{id} */
    public function destroy(array $params): array {
        $userId = $this->userId();
        if (!$userId) {
            return $this->error('غير مسجل دخول', 401);
        }

        $model = new Website();
        $website = $model->find((int) ($params['id'] ?? 0));

        if (!$website || (int) $website->getAttribute('user_id') !== (int) $userId) {
            return $this->error('الموقع غير موجود', 404);
        }

        if (!$website->delete()) {
            return $this->error('تعذر حذف الموقع', 500);
        }

        return $this->success([], 'تم حذف الموقع');
    }

    /**
     * POST /api/websites/{id}/verify
     * تحقق حقيقي من ملكية الموقع بطريقتين: meta tag أو DNS TXT.
     * محدش بيوافق تلقائيًا؛ لازم واحدة من الطريقتين تتحقق فعليًا.
     */
    public function verify(array $params): array {
        $userId = $this->userId();
        if (!$userId) {
            return $this->error('غير مسجل دخول', 401);
        }

        $model = new Website();
        $website = $model->find((int) ($params['id'] ?? 0));

        if (!$website || (int) $website->getAttribute('user_id') !== (int) $userId) {
            return $this->error('الموقع غير موجود', 404);
        }

        $mainUrl = $this->normalizeUrl((string) $website->getAttribute('main_url'));
        $host = parse_url($mainUrl, PHP_URL_HOST);
        $instructions = $this->verificationInstructions($website);
        $token = $instructions['token'];

        if (!$host) {
            return $this->error('رابط الموقع غير صالح', 422);
        }

        $metaOk = $this->checkMetaTag($mainUrl, $token);
        $dnsOk = $metaOk ? false : $this->checkDnsTxt($host, $token);

        if (!$metaOk && !$dnsOk) {
            return $this->error(
                'لم نقدر نتأكد من ملكية الموقع. تأكد إنك ضفت وسم meta tag في صفحتك الرئيسية أو سجل DNS TXT الصحيح، وإن الرابط شغال ويرد بنجاح.',
                422,
                ['verification' => $instructions]
            );
        }

        $website->setAttribute('is_verified', 1);
        $website->save();

        $this->log('Website Verified', ['website_id' => $website->getAttribute('id'), 'method' => $metaOk ? 'meta_tag' : 'dns_txt']);

        return $this->success([
            'website' => $website->toArray(),
            'method' => $metaOk ? 'meta_tag' : 'dns_txt',
        ], 'تم تأكيد ملكية الموقع فعليًا ✔');
    }

    /** POST /api/websites/{id}/competitors */
    public function updateCompetitors(array $params): array {
        $userId = $this->userId();
        if (!$userId) {
            return $this->error('غير مسجل دخول', 401);
        }

        $model = new Website();
        $website = $model->find((int) ($params['id'] ?? 0));

        if (!$website || (int) $website->getAttribute('user_id') !== (int) $userId) {
            return $this->error('الموقع غير موجود', 404);
        }

        foreach (['competitor_1_url', 'competitor_2_url', 'competitor_3_url'] as $field) {
            if ($this->has($field)) {
                $website->setAttribute($field, $this->get($field));
            }
        }

        if ($website->save() === false) {
            return $this->error('تعذر تحديث المنافسين', 500);
        }

        return $this->success(['website' => $website->toArray()], 'تم تحديث المنافسين');
    }
}
