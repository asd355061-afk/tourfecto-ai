(function () {
    const P = window.Panel;
    const esc = P.esc, fetchJSON = P.fetchJSON, toast = P.toast, formatDate = P.formatDate;

    const SECTION_LABELS = {
        company_info: 'معلومات الشركة', service: 'الخدمات', tour: 'الرحلات/الجولات',
        destination: 'الوجهات', pricing: 'الأسعار', faq: 'الأسئلة الشائعة',
        policy: 'السياسات', cancellation_policy: 'سياسة الإلغاء',
        contact_info: 'بيانات التواصل', business_hours: 'ساعات العمل',
        custom_instructions: 'تعليمات مخصصة',
    };

    function websiteId() { return P.getCurrentWebsiteId(); }

    function ensureWebsite() {
        const id = websiteId();
        document.getElementById('kbNoWebsite').style.display = id ? 'none' : 'block';
        document.getElementById('kbBody').style.display = id ? 'block' : 'none';
        return id;
    }

    window.kbAddEntry = async function () {
        const id = ensureWebsite();
        if (!id) return;
        const section = document.getElementById('kbSection').value;
        const language = document.getElementById('kbLanguage').value;
        const priority = parseInt(document.getElementById('kbPriority').value, 10) || 0;
        const title = document.getElementById('kbTitle').value.trim();
        const content = document.getElementById('kbContent').value.trim();
        if (!content) { toast('اكتب المحتوى أولاً', 'error'); return; }

        const res = await fetchJSON('/api/ai-chat/websites/' + id + '/knowledge-base', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ section: section, language: language, priority: priority, title: title || null, content: content }),
        });

        if (res.success) {
            toast('تمت الإضافة', 'success');
            document.getElementById('kbTitle').value = '';
            document.getElementById('kbContent').value = '';
            load();
        } else {
            toast(res.error || 'فشلت الإضافة', 'error');
        }
    };

    window.kbDeleteEntry = async function (entryId) {
        const id = websiteId();
        if (!confirm('تأكيد حذف هذه المعلومة؟')) return;
        const res = await fetchJSON('/api/ai-chat/websites/' + id + '/knowledge-base/' + entryId, { method: 'DELETE' });
        if (res.success) { toast('تم الحذف', 'success'); load(); }
        else { toast(res.error || 'فشل الحذف', 'error'); }
    };

    window.kbEditEntry = async function (entryId, currentContent, currentPriority) {
        const id = websiteId();
        const content = prompt('عدّل المحتوى:', currentContent || '');
        if (content === null) return;
        if (!content.trim()) { toast('المحتوى مطلوب', 'error'); return; }
        const priorityInput = prompt('الأولوية (0 عادية، 1 مرتفعة، 2 قصوى، -1 منخفضة):', String(currentPriority ?? 0));
        if (priorityInput === null) return;
        const priority = parseInt(priorityInput, 10);
        const priorityVal = isNaN(priority) ? 0 : priority;

        const res = await fetchJSON('/api/ai-chat/websites/' + id + '/knowledge-base/' + entryId, {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ content: content.trim(), priority: priorityVal }),
        });
        if (res.success) { toast('تم التعديل', 'success'); load(); }
        else { toast(res.error || 'فشل التعديل', 'error'); }
    };

    window.kbSaveBrandVoice = async function () {
        const id = ensureWebsite();
        if (!id) return;
        const tone = document.getElementById('bvTone').value;
        const instructions = document.getElementById('bvInstructions').value.trim();

        const res = await fetchJSON('/api/ai-chat/websites/' + id + '/knowledge-base', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ section: 'brand_voice', tone: tone, content: instructions || null }),
        });

        if (res.success) { toast('تم حفظ نبرة الشركة', 'success'); }
        else { toast(res.error || 'فشل الحفظ', 'error'); }
    };

    window.kbPreview = async function () {
        const id = websiteId();
        if (!id) { toast('اختر موقعًا أولاً', 'error'); return; }
        const res = await fetchJSON('/api/ai-chat/websites/' + id + '/knowledge-base/preview');
        if (!res.success) { toast(res.error || 'فشلت المعاينة', 'error'); return; }
        alert(res.data.context_preview || 'لا يوجد محتوى بعد');
    };

    async function load() {
        const id = ensureWebsite();
        if (!id) return;

        const res = await fetchJSON('/api/ai-chat/websites/' + id + '/knowledge-base');
        const container = document.getElementById('kbSectionsContainer');

        if (!res.success) {
            container.innerHTML = '<div class="p-card"><div class="p-empty">⚠️ ' + esc(res.error || 'تعذر التحميل') + '</div></div>';
            return;
        }

        if (res.data.brand_voice) {
            document.getElementById('bvTone').value = res.data.brand_voice.tone || 'professional';
            document.getElementById('bvInstructions').value = res.data.brand_voice.custom_instructions || '';
        }

        const sections = res.data.sections || {};
        const sectionKeys = Object.keys(sections);
        if (!sectionKeys.length) {
            container.innerHTML = '<div class="p-card"><div class="p-empty"><div class="p-empty-icon">📚</div>مفيش أي معلومات مضافة لقاعدة المعرفة بعد. أضف أول معلومة من الفورم أعلاه - من غيرها الذكاء الاصطناعي مش هيقدر يجاوب على أي سؤال محدد عن شركتك.</div></div>';
            return;
        }

        container.innerHTML = sectionKeys.map(section => {
            const entries = sections[section];
            const rows = entries.map(e => `
                <div class="p-kv" style="align-items:flex-start;">
                    <span class="k" style="max-width:70%;">
                        ${e.title ? '<strong>' + esc(e.title) + '</strong><br>' : ''}
                        ${esc(e.content || '')}
                        <span class="p-cell-muted"> · ${e.language === 'en' ? 'EN' : 'AR'}</span>
                        ${e.priority ? '<span class="pill blue">أولوية ' + e.priority + '</span>' : ''}
                    </span>
                    <span style="display:flex;gap:6px;">
                        <button class="p-btn outline xs" onclick="kbEditEntry(${e.id}, ${JSON.stringify(e.content || '').replace(/"/g, '&quot;')}, ${e.priority ?? 0})">تعديل</button>
                        <button class="p-btn danger xs" onclick="kbDeleteEntry(${e.id})">حذف</button>
                    </span>
                </div>`).join('');
            return `
                <div class="p-card" style="margin-bottom:14px;">
                    <div class="p-card-head"><h3>${SECTION_LABELS[section] || esc(section)}</h3><span class="p-card-sub">${entries.length} عنصر</span></div>
                    ${rows}
                </div>`;
        }).join('');
    }

    window.addEventListener('tourfecto:website-changed', load);
    load();
})();
