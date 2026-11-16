(() => {
    'use strict';
    const $ = (selector) => document.querySelector(selector);
    const preview = $('#igreja-brand-preview');
    if (!preview) return;
    const contrast = (hex) => {
        let color = hex.replace('#', '');
        if (color.length === 3) color = [...color].map(x => x + x).join('');
        const values = [0, 2, 4].map(i => parseInt(color.slice(i, i + 2), 16) / 255).map(v => v <= .04045 ? v / 12.92 : ((v + .055) / 1.055) ** 2.4);
        return .2126 * values[0] + .7152 * values[1] + .0722 * values[2] > .179 ? '#000000' : '#FFFFFF';
    };
    const colorNames = {cor_primaria:'primary', cor_destaque:'accent', cor_fundo:'surface', cor_texto:'text'};
    document.querySelectorAll('[data-color]').forEach(input => {
        input.addEventListener('input', () => {
            const name = colorNames[input.dataset.color];
            if (!name) return;
            document.documentElement.style.setProperty('--igreja-' + name, input.value);
            input.parentElement.querySelector('output').textContent = input.value.toUpperCase();
            if (name === 'primary' || name === 'accent') {
                document.documentElement.style.setProperty('--igreja-on-' + name, contrast(input.value));
            }
        });
    });
    document.querySelectorAll('[data-preview]').forEach(input => {
        input.addEventListener('input', () => {
            const target = input.dataset.preview === 'name' ? $('#igreja-preview-name') : $('#igreja-preview-message');
            target.textContent = input.value;
        });
    });
    const logoId = $('#igreja-logo-id');
    const logoUrl = $('#igreja-logo-url');
    const renderLogo = (url) => {
        let allowed = '';
        try {
            const parsed = new URL(url);
            if (parsed.protocol === 'https:' || parsed.protocol === 'http:') allowed = parsed.href;
        } catch (_) {}
        ['#igreja-logo-preview', '#igreja-preview-logo'].forEach(selector => {
            const img = $(selector);
            img.hidden = !allowed;
            if (allowed) img.src = allowed; else img.removeAttribute('src');
        });
        $('#igreja-logo-empty').hidden = !!allowed;
    };
    logoUrl.addEventListener('input', () => {logoId.value = '0'; renderLogo(logoUrl.value);});
    $('#igreja-remove-logo').addEventListener('click', () => {logoId.value = '0'; logoUrl.value = ''; renderLogo('');});
    let media;
    $('#igreja-select-logo').addEventListener('click', () => {
        if (!window.wp || !wp.media) return;
        if (!media) {
            media = wp.media({title:'Escolher logo da igreja', button:{text:'Usar esta logo'}, library:{type:'image'}, multiple:false});
            media.on('select', () => {
                const selected = media.state().get('selection').first().toJSON();
                logoId.value = String(selected.id);
                logoUrl.value = selected.url;
                renderLogo(selected.url);
            });
        }
        media.open();
    });
})();
