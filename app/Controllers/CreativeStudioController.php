<?php

/**
 * Tourfecto - Creative Studio Controller
 * لوحة Creative Studio: مكتبة وسائط (صور + فيديوهات قصيرة بالذكاء
 * الاصطناعي) + سكربتات فيديو + نشر/جدولة مباشرة على السوشيال ميديا
 * (بيعيد استخدام SocialPostService/PublishSocialPostJob الموجودين
 * فعلاً - نفس تدفق صفحة /social، بس بادئ من هنا وبالوسائط مربوطة
 * تلقائيًا).
 * @version 2.0.0
 */
class CreativeStudioController extends Controller
{
    /** @var MediaGenerationService */
    private $mediaService;
    /** @var VideoScriptService */
    private $videoService;

    public function __construct()
    {
        parent::__construct();
        $this->mediaService = new MediaGenerationService();
        $this->videoService = new VideoScriptService();
    }

    /** GET /creative-studio */
    public function index(array $params = []): array
    {
        $body = <<<HTML
        <div class="p-toolbar">
            <button class="p-btn" onclick="document.getElementById('mediaModal').classList.add('open')">🎨 توليد صورة</button>
            <button class="p-btn" onclick="document.getElementById('genVideoModal').classList.add('open')">🎥 توليد فيديو قصير</button>
            <button class="p-btn outline" onclick="document.getElementById('videoModal').classList.add('open')">📝 سكربت فيديو</button>
        </div>

        <div class="p-tabs" id="csTabs" style="margin-bottom:16px;">
            <button class="p-tab active" data-tab="media">🖼️ الصور والفيديوهات</button>
            <button class="p-tab" data-tab="scripts">📝 سكربتات الفيديو</button>
        </div>

        <div class="p-grid cols-4" id="mediaGrid"><div class="p-empty">جارِ التحميل...</div></div>
        <div id="scriptsGrid" style="display:none;"><div class="p-empty">جارِ التحميل...</div></div>

        <!-- توليد صورة -->
        <div class="p-modal-overlay" id="mediaModal">
            <div class="p-modal">
                <div class="p-modal-head"><h3>توليد صورة تسويقية احترافية</h3><button class="p-modal-close" onclick="document.getElementById('mediaModal').classList.remove('open')">×</button></div>
                <div class="p-modal-body">
                    <label>النوع</label>
                    <select id="mediaType" class="p-select" style="width:100%;margin-bottom:10px;">
                        <option value="social_image">صورة سوشيال ميديا (1:1)</option>
                        <option value="marketing_image">صورة تسويقية (4:3)</option>
                        <option value="instagram_post">منشور انستجرام (1:1)</option>
                        <option value="facebook_cover">غلاف فيسبوك (16:9)</option>
                        <option value="youtube_thumbnail">صورة يوتيوب مصغرة (16:9)</option>
                        <option value="story">ستوري (9:16)</option>
                        <option value="reels_cover">غلاف ريلز (9:16)</option>
                    </select>
                    <label>الأسلوب البصري</label>
                    <select id="mediaStyle" class="p-select" style="width:100%;margin-bottom:10px;">
                        <option value="photo">تصوير احترافي واقعي</option>
                        <option value="cinematic">سينمائي</option>
                        <option value="product">تصوير منتج (استوديو)</option>
                        <option value="illustration">رسم/إليستريشن</option>
                        <option value="minimal">مينيمال بسيط</option>
                    </select>
                    <label>الوصف</label>
                    <textarea id="mediaPrompt" rows="3" style="width:100%;" class="p-select" placeholder="مثال: منظر شاطئ الغردقة عند الغروب بألوان دافئة"></textarea>
                    <button class="p-btn outline xs" style="margin-top:8px;" onclick="enhancePrompt('mediaPrompt', 'image', this)">✨ تحسين الوصف بالذكاء الاصطناعي</button>
                </div>
                <div class="p-modal-foot"><button class="p-btn" onclick="requestMedia()">توليد</button></div>
            </div>
        </div>

        <!-- توليد فيديو قصير حقيقي -->
        <div class="p-modal-overlay" id="genVideoModal">
            <div class="p-modal">
                <div class="p-modal-head"><h3>توليد فيديو قصير بالذكاء الاصطناعي</h3><button class="p-modal-close" onclick="document.getElementById('genVideoModal').classList.remove('open')">×</button></div>
                <div class="p-modal-body">
                    <label>الوصف (المشهد اللي عايز الفيديو يوريه)</label>
                    <textarea id="genVideoPrompt" rows="3" style="width:100%;" class="p-select" placeholder="مثال: لقطة سينمائية لطائر بحري يحلّق فوق شاطئ الغردقة وقت الغروب"></textarea>
                    <button class="p-btn outline xs" style="margin-top:8px;margin-bottom:10px;" onclick="enhancePrompt('genVideoPrompt', 'video', this)">✨ تحسين الوصف بالذكاء الاصطناعي</button>
                    <label>المنصة (بيحدد شكل الفيديو)</label>
                    <select id="genVideoPlatform" class="p-select" style="width:100%;margin-bottom:10px;">
                        <option value="instagram_reels">Instagram Reels (عمودي)</option>
                        <option value="tiktok">TikTok (عمودي)</option>
                        <option value="youtube_shorts">YouTube Shorts (عمودي)</option>
                        <option value="general_landscape">عرضي عام (16:9)</option>
                    </select>
                    <label>الأسلوب البصري</label>
                    <select id="genVideoStyle" class="p-select" style="width:100%;margin-bottom:10px;">
                        <option value="cinematic">سينمائي</option>
                        <option value="photo">واقعي احترافي</option>
                        <option value="product">عرض منتج</option>
                        <option value="minimal">مينيمال بسيط</option>
                    </select>
                    <label>المدة</label>
                    <select id="genVideoDuration" class="p-select" style="width:100%;">
                        <option value="4">4 ثواني</option>
                        <option value="6">6 ثواني</option>
                        <option value="8" selected>8 ثواني</option>
                    </select>
                    <p class="p-cell-muted" style="font-size:12px;margin-top:10px;">⏳ توليد الفيديو بياخد من دقيقة لحد ~5 دقايق - الفيديو هيظهر تلقائيًا في المكتبة أول ما يخلص.</p>
                </div>
                <div class="p-modal-foot"><button class="p-btn" id="genVideoBtn" onclick="requestVideo()">توليد الفيديو</button></div>
            </div>
        </div>

        <!-- سكربت فيديو نصي -->
        <div class="p-modal-overlay" id="videoModal">
            <div class="p-modal">
                <div class="p-modal-head"><h3>سكربت فيديو قصير</h3><button class="p-modal-close" onclick="document.getElementById('videoModal').classList.remove('open')">×</button></div>
                <div class="p-modal-body">
                    <label>الموضوع</label>
                    <input type="text" id="videoTopic" class="p-select" style="width:100%;margin-bottom:10px;">
                    <label>المنصة</label>
                    <select id="videoPlatform" class="p-select" style="width:100%;margin-bottom:10px;">
                        <option value="tiktok">TikTok</option>
                        <option value="instagram_reels">Instagram Reels</option>
                        <option value="youtube_shorts">YouTube Shorts</option>
                    </select>
                    <label>المدة (ثانية)</label>
                    <select id="videoDuration" class="p-select" style="width:100%;">
                        <option value="15">15 ثانية</option>
                        <option value="30" selected>30 ثانية</option>
                        <option value="60">60 ثانية</option>
                    </select>
                </div>
                <div class="p-modal-foot"><button class="p-btn" id="genScriptBtn" onclick="requestVideoScript()">توليد السكربت</button></div>
            </div>
        </div>

        <!-- نشر / جدولة عنصر وسائط -->
        <div class="p-modal-overlay" id="publishModal">
            <div class="p-modal">
                <div class="p-modal-head"><h3>نشر / جدولة</h3><button class="p-modal-close" onclick="document.getElementById('publishModal').classList.remove('open')">×</button></div>
                <div class="p-modal-body">
                    <input type="hidden" id="publishMediaId">
                    <label>نص المنشور</label>
                    <textarea id="publishContent" rows="4" style="width:100%;" class="p-select"></textarea>
                    <button class="p-btn outline xs" style="margin-top:8px;" onclick="generatePublishCaption()">✨ توليد نص بالذكاء الاصطناعي</button>

                    <label style="margin-top:14px;display:block;">انشر على</label>
                    <div id="publishTargetsList" class="p-cell-muted">جارِ تحميل الصفحات المتصلة...</div>

                    <label style="margin-top:14px;display:block;">التوقيت</label>
                    <div style="display:flex;gap:14px;align-items:center;margin-bottom:8px;">
                        <label style="display:flex;align-items:center;gap:6px;"><input type="radio" name="publishWhen" value="now" checked onchange="togglePublishSchedule()"> انشر الآن</label>
                        <label style="display:flex;align-items:center;gap:6px;"><input type="radio" name="publishWhen" value="later" onchange="togglePublishSchedule()"> جدولة لموعد لاحق</label>
                    </div>
                    <input type="datetime-local" id="publishScheduleAt" class="p-select" style="width:100%;display:none;">
                </div>
                <div class="p-modal-foot">
                    <button class="p-btn" id="publishSubmitBtn" onclick="submitPublish()">نشر</button>
                </div>
            </div>
        </div>
HTML;

        $script = <<<'JS'
(function () {
    const P = window.Panel;
    const esc = P.esc, fetchJSON = P.fetchJSON;
    const jsonHeaders = { 'Content-Type': 'application/json' };

    document.querySelectorAll('#csTabs .p-tab').forEach(btn => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('#csTabs .p-tab').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            const tab = btn.dataset.tab;
            document.getElementById('mediaGrid').style.display = tab === 'media' ? 'grid' : 'none';
            document.getElementById('scriptsGrid').style.display = tab === 'scripts' ? 'block' : 'none';
        });
    });

    async function load() {
        const res = await fetchJSON('/api/creative-studio/media');
        const grid = document.getElementById('mediaGrid');
        if (res.success && res.data.items && res.data.items.length) {
            grid.innerHTML = res.data.items.map(m => {
                const isVideo = m.type === 'short_video';
                let preview;
                if (m.status === 'completed' && m.file_path) {
                    preview = isVideo
                        ? `<video src="${esc(m.file_path)}" controls preload="metadata" style="width:100%;aspect-ratio:9/16;object-fit:cover;border-radius:10px 10px 0 0;display:block;background:#000;"></video>`
                        : `<a href="${esc(m.file_path)}" target="_blank" rel="noopener"><img src="${esc(m.file_path)}" alt="${esc((m.prompt || 'صورة مولّدة').slice(0, 120))}" style="width:100%;aspect-ratio:1/1;object-fit:cover;border-radius:10px 10px 0 0;display:block;"></a>`;
                } else {
                    preview = `<div style="width:100%;aspect-ratio:1/1;display:flex;align-items:center;justify-content:center;background:var(--panel-bg,#f7f8fa);border-radius:10px 10px 0 0;font-size:28px;">${m.status === 'failed' ? '⚠️' : (isVideo ? '🎬' : '⏳')}</div>`;
                }

                const publishBtn = (m.status === 'completed' && m.file_path)
                    ? `<button class="p-btn outline xs" style="margin-top:8px;width:100%;" onclick='openPublish(${m.id}, ${JSON.stringify(m.prompt || '').replace(/'/g, "&#39;")})'>📤 نشر / جدولة</button>`
                    : '';

                return `
                <div class="p-card no-pad">
                    ${preview}
                    <div style="padding:12px;">
                        <p class="p-cell-muted" style="font-size:12.5px;margin-bottom:6px;">${isVideo ? '🎬 ' : ''}${esc((m.prompt || '').slice(0, 60))}</p>
                        <span class="pill ${m.status === 'completed' ? 'green' : (m.status === 'failed' ? 'red' : 'orange')}">${esc(statusLabel(m.status))}</span>
                        ${m.status === 'failed' && m.error_message ? `<p class="p-cell-muted" style="font-size:11px;margin-top:6px;">${esc(m.error_message)}</p>` : ''}
                        ${publishBtn}
                    </div>
                </div>
            `;}).join('');

            if (res.data.items.some(m => m.status === 'generating')) {
                setTimeout(load, 5000);
            }
        } else {
            grid.innerHTML = '<div class="p-empty"><div class="p-empty-icon">🎨</div>لا يوجد عناصر بعد</div>';
        }
    }

    async function loadScripts() {
        const res = await fetchJSON('/api/creative-studio/video-scripts');
        const box = document.getElementById('scriptsGrid');
        if (res.success && res.data.scripts && res.data.scripts.length) {
            box.innerHTML = res.data.scripts.map(s => {
                let scenes = [];
                try { scenes = JSON.parse(s.scenes || '[]'); } catch (e) { scenes = []; }
                const scenesHtml = scenes.length ? `
                    <div class="p-table-scroll" style="margin-top:10px;"><table class="p-table">
                        <thead><tr><th>التوقيت</th><th>المشهد المرئي</th><th>الصوت (Voiceover)</th></tr></thead>
                        <tbody>${scenes.map(sc => `<tr><td style="white-space:nowrap;">${esc(sc.time || '-')}</td><td>${esc(sc.visual || '-')}</td><td>${esc(sc.voiceover || '-')}</td></tr>`).join('')}</tbody>
                    </table></div>` : '';

                return `
                <div class="p-card" style="margin-bottom:14px;">
                    <div class="p-card-head">
                        <h3>${esc(s.topic)}</h3>
                        <span class="p-card-sub">${esc(s.platform)} · ${esc(s.duration_seconds)} ثانية</span>
                    </div>
                    ${s.status === 'completed'
                        ? `<p style="white-space:pre-wrap;line-height:1.8;background:var(--panel-bg,#f7f8fa);padding:12px 14px;border-radius:8px;">${esc(s.script_text || '')}</p>${scenesHtml}
                           <button class="p-btn outline xs" style="margin-top:10px;" onclick="copyScript(this)" data-text="${esc(s.script_text || '')}">📋 نسخ النص</button>`
                        : (s.status === 'failed'
                            ? `<div class="alert alert-danger">فشل توليد السكربت</div>`
                            : `<div class="p-cell-muted">⏳ جارِ التوليد...</div>`)
                    }
                </div>`;
            }).join('');
        } else {
            box.innerHTML = '<div class="p-empty"><div class="p-empty-icon">🎬</div>لا يوجد سكربتات فيديو بعد</div>';
        }
    }

    window.copyScript = function (btn) {
        navigator.clipboard.writeText(btn.dataset.text).then(() => P.toast('اتنسخ النص ✔', 'success'));
    };

    function statusLabel(status) {
        return { completed: 'جاهزة', failed: 'فشل التوليد', generating: 'جارِ التوليد...' }[status] || status;
    }

    window.enhancePrompt = async function (textareaId, kind, btn) {
        const el = document.getElementById(textareaId);
        const text = el.value.trim();
        if (!text) return;
        const original = btn.textContent;
        btn.disabled = true; btn.textContent = 'جارِ التحسين...';
        const res = await fetchJSON('/api/creative-studio/enhance-prompt', { method: 'POST', headers: jsonHeaders, body: JSON.stringify({ prompt: text, kind }) });
        btn.disabled = false; btn.textContent = original;
        if (res.success) { el.value = res.data.prompt; }
        else { P.toast(res.error || 'فشل التحسين', 'error'); }
    };

    window.requestMedia = async function () {
        const type = document.getElementById('mediaType').value;
        const style = document.getElementById('mediaStyle').value;
        const prompt = document.getElementById('mediaPrompt').value.trim();
        if (!prompt) return;
        const res = await fetchJSON('/api/creative-studio/media', { method: 'POST', headers: jsonHeaders, body: JSON.stringify({ type, prompt, style }) });
        document.getElementById('mediaModal').classList.remove('open');
        if (res.success) { P.toast('تم إرسال طلب التوليد', 'success'); load(); }
        else { P.toast(res.error || 'فشل الطلب', 'error'); }
    };

    window.requestVideo = async function () {
        const prompt = document.getElementById('genVideoPrompt').value.trim();
        const platform = document.getElementById('genVideoPlatform').value;
        const style = document.getElementById('genVideoStyle').value;
        const duration_seconds = document.getElementById('genVideoDuration').value;
        if (!prompt) return;

        const btn = document.getElementById('genVideoBtn');
        btn.disabled = true;
        const original = btn.textContent;
        btn.textContent = 'جارِ إرسال الطلب...';

        const res = await fetchJSON('/api/creative-studio/video', { method: 'POST', headers: jsonHeaders, body: JSON.stringify({ prompt, platform, style, duration_seconds }) });

        btn.disabled = false;
        btn.textContent = original;
        document.getElementById('genVideoModal').classList.remove('open');

        if (res.success) { P.toast('بدأ توليد الفيديو - هيظهر في المكتبة أول ما يخلص ✔', 'success'); load(); }
        else { P.toast(res.error || 'فشل الطلب', 'error'); }
    };

    window.requestVideoScript = async function () {
        const topic = document.getElementById('videoTopic').value.trim();
        const platform = document.getElementById('videoPlatform').value;
        const duration_seconds = document.getElementById('videoDuration').value;
        if (!topic) return;

        const btn = document.getElementById('genScriptBtn');
        btn.disabled = true;
        const original = btn.textContent;
        btn.textContent = 'جارِ التوليد... قد يستغرق شوية';

        const res = await fetchJSON('/api/creative-studio/video-scripts', { method: 'POST', headers: jsonHeaders, body: JSON.stringify({ topic, platform, duration_seconds }) });

        btn.disabled = false;
        btn.textContent = original;
        document.getElementById('videoModal').classList.remove('open');

        if (res.success) {
            P.toast('تم توليد السكربت ✔', 'success');
            document.querySelector('#csTabs [data-tab="scripts"]').click();
            loadScripts();
        } else {
            P.toast(res.error || 'فشل التوليد', 'error');
        }
    };

    // ---------- نشر / جدولة ----------
    let publishTargetsLoaded = false;

    window.togglePublishSchedule = function () {
        const later = document.querySelector('input[name="publishWhen"]:checked').value === 'later';
        document.getElementById('publishScheduleAt').style.display = later ? 'block' : 'none';
    };

    window.openPublish = async function (mediaId, prompt) {
        document.getElementById('publishMediaId').value = mediaId;
        document.getElementById('publishContent').value = '';
        document.getElementById('publishContent').dataset.topic = prompt || '';
        document.getElementById('publishModal').classList.add('open');

        if (!publishTargetsLoaded) {
            const res = await fetchJSON('/api/social/connections');
            const box = document.getElementById('publishTargetsList');
            if (res.success && res.data.connections && res.data.connections.length) {
                box.innerHTML = res.data.connections.map(c => `
                    <label style="display:flex;align-items:center;gap:8px;margin:6px 0;">
                        <input type="checkbox" class="publishTargetCheckbox" value="${c.id}">
                        ${c.platform === 'facebook' ? '📘' : '📸'} ${esc(c.name)}
                    </label>`).join('');
                publishTargetsLoaded = true;
            } else {
                box.innerHTML = 'مفيش صفحات فيسبوك/انستجرام متصلة لسه. اربط حساب Meta الأول من <a href="/ads" target="_blank">صفحة الإعلانات</a>.';
            }
        }
    };

    window.generatePublishCaption = async function () {
        const topic = document.getElementById('publishContent').dataset.topic || 'محتوى تسويقي';
        const res = await fetchJSON('/api/social/generate-caption', { method: 'POST', headers: jsonHeaders, body: JSON.stringify({ topic, platform: 'instagram' }) });
        if (res.success) { document.getElementById('publishContent').value = res.data.content; }
        else { P.toast(res.error || 'فشل التوليد', 'error'); }
    };

    window.submitPublish = async function () {
        const mediaId = parseInt(document.getElementById('publishMediaId').value, 10);
        const content = document.getElementById('publishContent').value.trim();
        if (!content) { P.toast('اكتب نص المنشور الأول', 'error'); return; }

        const targetIds = Array.from(document.querySelectorAll('.publishTargetCheckbox:checked')).map(cb => parseInt(cb.value, 10));
        if (!targetIds.length) { P.toast('اختار صفحة واحدة على الأقل للنشر عليها', 'error'); return; }

        const isLater = document.querySelector('input[name="publishWhen"]:checked').value === 'later';
        const scheduledAt = isLater ? document.getElementById('publishScheduleAt').value : null;
        if (isLater && !scheduledAt) { P.toast('اختار موعد الجدولة', 'error'); return; }

        const targets = targetIds.map(id => ({ platform_connection_id: id, scheduled_at: scheduledAt ? scheduledAt.replace('T', ' ') + ':00' : null }));

        const btn = document.getElementById('publishSubmitBtn');
        btn.disabled = true;
        const res = await fetchJSON('/api/social/posts', { method: 'POST', headers: jsonHeaders, body: JSON.stringify({ content, media_item_id: mediaId, targets }) });
        btn.disabled = false;

        if (res.success) {
            document.getElementById('publishModal').classList.remove('open');
            P.toast(isLater ? 'تمت الجدولة ✔' : 'جارِ النشر الآن ✔', 'success');
        } else {
            P.toast(res.error || 'فشل النشر', 'error');
        }
    };

    load();
    loadScripts();
})();
JS;

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('creative_studio', 'Creative Studio', 'توليد صور وفيديوهات احترافية بالذكاء الاصطناعي، وانشرها أو جدولها مباشرة', $body, $script);
        exit;
    }

    /** GET /api/creative-studio/media */
    public function listMedia(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $items = (new MediaItem())->where(['user_id' => $this->user['id']], ['created_at' => 'DESC'], 40);
        return $this->success(['items' => array_map(fn ($i) => $i->toArray(), $items)]);
    }

    /** POST /api/creative-studio/media - توليد صورة */
    public function requestMedia(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if (!$this->validate(['type' => 'required', 'prompt' => 'required'])) {
            return $this->error('بيانات ناقصة', 422);
        }

        try {
            $item = $this->mediaService->requestGeneration(
                (int) $this->user['id'],
                (string) $this->get('type'),
                (string) $this->get('prompt'),
                (string) $this->get('style', 'photo')
            );
            return $this->success(['item' => $item->toArray()], 'تم استلام طلب التوليد', 201);
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (Exception $e) {
            Logger::error('requestMedia Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر إنشاء طلب التوليد', 500);
        }
    }

    /** POST /api/creative-studio/video - توليد فيديو قصير حقيقي (Veo) */
    public function requestVideo(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if (!$this->validate(['prompt' => 'required'])) {
            return $this->error('الوصف مطلوب', 422);
        }

        try {
            $item = $this->mediaService->requestVideoGeneration(
                (int) $this->user['id'],
                (string) $this->get('prompt'),
                (string) $this->get('platform', 'instagram_reels'),
                (int) $this->get('duration_seconds', 8),
                (string) $this->get('style', 'cinematic')
            );
            return $this->success(['item' => $item->toArray()], 'بدأ توليد الفيديو', 201);
        } catch (Exception $e) {
            Logger::error('requestVideo Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر إنشاء طلب توليد الفيديو', 500);
        }
    }

    /** POST /api/creative-studio/enhance-prompt - تحسين وصف بالذكاء الاصطناعي قبل التوليد */
    public function enhancePrompt(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if (!$this->validate(['prompt' => 'required'])) {
            return $this->error('الوصف مطلوب', 422);
        }

        $prompt = (string) $this->get('prompt');
        $kind = (string) $this->get('kind', 'image');
        $mediaLabel = $kind === 'video' ? 'مشهد فيديو قصير' : 'صورة تسويقية';

        $metaPrompt = <<<PROMPT
مهمتك: حوّل الوصف القصير التالي لـ{$mediaLabel} إلى وصف احترافي مفصّل بالإنجليزية
يصلح كـ prompt لنموذج توليد وسائط بالذكاء الاصطناعي - أضف تفاصيل عن الإضاءة،
زاوية التصوير، التكوين، والأجواء، من غير ما تغيّر الفكرة الأساسية.
الوصف الأصلي: "{$prompt}"
رجّع النص المحسّن فقط، من غير أي شرح أو علامات اقتباس.
PROMPT;

        $ai = new GeminiClient();
        $response = $ai->generateContent($metaPrompt, ['maxOutputTokens' => 512, 'temperature' => 0.6]);

        if (!($response['success'] ?? false)) {
            return $this->error($response['error'] ?? 'فشل الاتصال بمحرك الذكاء الاصطناعي', 502);
        }

        $enhanced = trim((string) ($response['data'] ?? ''));
        $enhanced = trim($enhanced, "\"'`\n ");

        if ($enhanced === '') {
            return $this->error('تعذّر تحسين الوصف', 502);
        }

        return $this->success(['prompt' => $enhanced]);
    }

    /** GET /api/creative-studio/video-scripts */
    public function listVideoScripts(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $items = (new VideoScript())->where(['user_id' => $this->user['id']], ['created_at' => 'DESC'], 40);
        return $this->success(['scripts' => array_map(fn ($i) => $i->toArray(), $items)]);
    }

    /** POST /api/creative-studio/video-scripts */
    public function requestVideoScript(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if (!$this->validate(['topic' => 'required'])) {
            return $this->error('الموضوع مطلوب', 422);
        }

        try {
            $script = $this->videoService->generate(
                (int) $this->user['id'],
                (string) $this->get('topic'),
                (string) $this->get('platform', 'general'),
                (int) $this->get('duration_seconds', 30),
                (string) ($this->user['language'] ?? 'ar')
            );
            return $this->success(['script' => $script->toArray()], 'تم توليد السكربت', 201);
        } catch (Exception $e) {
            Logger::error('requestVideoScript Error', ['message' => $e->getMessage()]);
            return $this->error($e->getMessage(), 502);
        }
    }
}
