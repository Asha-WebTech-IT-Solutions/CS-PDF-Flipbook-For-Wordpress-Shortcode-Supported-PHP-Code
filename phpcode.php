<?php
/**
 * Plugin Name: CS PDF Reader
 * Description: Responsive, readable PDF viewer for WordPress with upload or URL sources, per-document shortcodes, page navigation, zoom and fullscreen.
 * Version: 3.0.0
 * Author: CS
 */

if (!defined('ABSPATH')) exit;

class CS_PDF_Reader {
    const CPT = 'cs_pdf_reader';
    const SOURCE = '_cspr_source';
    const ATTACHMENT = '_cspr_attachment';
    const URL = '_cspr_url';

    public function __construct() {
        add_action('init', [$this, 'register_cpt']);
        add_action('add_meta_boxes', [$this, 'add_metabox']);
        add_action('save_post', [$this, 'save_metabox'], 10, 2);
        add_action('admin_enqueue_scripts', [$this, 'admin_assets']);
        add_action('wp_enqueue_scripts', [$this, 'register_frontend_assets']);
        add_shortcode('pdf_flipbook', [$this, 'shortcode']);
    }

    public function register_cpt() {
        register_post_type(self::CPT, [
            'labels' => [
                'name' => 'PDF Readers',
                'singular_name' => 'PDF Reader',
                'add_new_item' => 'Add New PDF',
                'edit_item' => 'Edit PDF',
                'menu_name' => 'PDF Flipbooks',
            ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => true,
            'menu_icon' => 'dashicons-media-document',
            'supports' => ['title'],
        ]);
    }

    public function add_metabox() {
        add_meta_box('cspr_settings', 'PDF Source & Shortcode', [$this, 'render_metabox'], self::CPT, 'normal', 'high');
    }

    public function render_metabox($post) {
        wp_nonce_field('cspr_save', 'cspr_nonce');
        $source = get_post_meta($post->ID, self::SOURCE, true) ?: 'upload';
        $attachment = absint(get_post_meta($post->ID, self::ATTACHMENT, true));
        $url = get_post_meta($post->ID, self::URL, true);
        $file_url = $attachment ? wp_get_attachment_url($attachment) : '';
        ?>
        <p>
            <label><input type="radio" name="cspr_source" value="upload" <?php checked($source, 'upload'); ?>> Upload / Media Library</label>
            &nbsp;&nbsp;
            <label><input type="radio" name="cspr_source" value="url" <?php checked($source, 'url'); ?>> PDF URL</label>
        </p>
        <div id="cspr-upload-panel">
            <input type="hidden" id="cspr_attachment" name="cspr_attachment" value="<?php echo esc_attr($attachment); ?>">
            <input type="text" id="cspr_file_url" value="<?php echo esc_url($file_url); ?>" readonly style="width:60%;max-width:600px">
            <button type="button" class="button" id="cspr_select">Upload / Select PDF</button>
            <button type="button" class="button" id="cspr_remove">Remove</button>
            <p class="description">For very large PDFs, upload through your hosting file manager/SFTP and add the file to Media Library. Server upload limits still apply.</p>
        </div>
        <div id="cspr-url-panel">
            <p><input type="url" name="cspr_url" id="cspr_url" value="<?php echo esc_attr($url); ?>" placeholder="https://example.com/document.pdf" style="width:100%;max-width:750px"></p>
            <p class="description">Use a publicly accessible PDF URL. Some external hosts block browser access; if so, use a local Media Library file.</p>
        </div>
        <?php if ($post->ID && get_post_status($post->ID) !== 'auto-draft'): ?>
            <hr><strong>Shortcode</strong><br>
            <code id="cspr_shortcode">[pdf_flipbook id="<?php echo esc_attr($post->ID); ?>"]</code>
            <button type="button" class="button" id="cspr_copy">Copy Shortcode</button>
        <?php else: ?>
            <p class="description">Save the PDF entry to generate its shortcode.</p>
        <?php endif;
    }

    public function admin_assets($hook) {
        $screen = get_current_screen();
        if (!$screen || $screen->post_type !== self::CPT) return;
        wp_enqueue_media();
        wp_enqueue_script('jquery');
        $js = <<<'JS'
jQuery(function($) {
    let frame;
    function toggle() {
        const isUrl = $('input[name="cspr_source"]:checked').val() === 'url';
        $('#cspr-upload-panel').toggle(!isUrl);
        $('#cspr-url-panel').toggle(isUrl);
    }
    $('input[name="cspr_source"]').on('change', toggle); toggle();

    $('#cspr_select').on('click', function(e) {
        e.preventDefault();
        if (frame) { frame.open(); return; }
        frame = wp.media({title:'Select or Upload PDF', button:{text:'Use this PDF'}, library:{type:'application/pdf'}, multiple:false});
        frame.on('select', function() {
            const file = frame.state().get('selection').first().toJSON();
            if (file.mime !== 'application/pdf' && !/\.pdf($|\?)/i.test(file.url)) { alert('Please select a PDF.'); return; }
            $('#cspr_attachment').val(file.id);
            $('#cspr_file_url').val(file.url);
        });
        frame.open();
    });
    $('#cspr_remove').on('click', function() { $('#cspr_attachment').val(''); $('#cspr_file_url').val(''); });
    $('#cspr_copy').on('click', async function() {
        const value = $('#cspr_shortcode').text();
        try { await navigator.clipboard.writeText(value); }
        catch(e) { const el=$('<textarea>').val(value).appendTo('body'); el[0].select(); document.execCommand('copy'); el.remove(); }
        const b=$(this); b.text('Copied!'); setTimeout(()=>b.text('Copy Shortcode'),1400);
    });
});
JS;
        wp_add_inline_script('jquery', $js);
    }

    public function save_metabox($post_id, $post) {
        if ($post->post_type !== self::CPT || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || wp_is_post_revision($post_id)) return;
        if (!isset($_POST['cspr_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['cspr_nonce'])), 'cspr_save')) return;
        if (!current_user_can('edit_post', $post_id)) return;

        $source = isset($_POST['cspr_source']) ? sanitize_key(wp_unslash($_POST['cspr_source'])) : 'upload';
        if (!in_array($source, ['upload','url'], true)) $source = 'upload';
        update_post_meta($post_id, self::SOURCE, $source);

        if ($source === 'upload') {
            $attachment = isset($_POST['cspr_attachment']) ? absint($_POST['cspr_attachment']) : 0;
            if ($attachment && get_post_mime_type($attachment) === 'application/pdf') update_post_meta($post_id, self::ATTACHMENT, $attachment);
            else delete_post_meta($post_id, self::ATTACHMENT);
            delete_post_meta($post_id, self::URL);
        } else {
            $url = isset($_POST['cspr_url']) ? esc_url_raw(trim(wp_unslash($_POST['cspr_url']))) : '';
            $scheme = $url ? strtolower((string)wp_parse_url($url, PHP_URL_SCHEME)) : '';
            if ($url && in_array($scheme, ['http','https'], true)) update_post_meta($post_id, self::URL, $url);
            else delete_post_meta($post_id, self::URL);
            delete_post_meta($post_id, self::ATTACHMENT);
        }
    }

    public function register_frontend_assets() {
        wp_register_script('cspr-pdfjs', 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js', [], '3.11.174', true);
        $js = <<<'JS'
(function() {
    function initViewer(root) {
        if (root.dataset.ready) return;
        root.dataset.ready = '1';

        const canvas = root.querySelector('canvas');
        const ctx = canvas.getContext('2d', {alpha:false});
        const status = root.querySelector('.cspr-status');
        const pageInput = root.querySelector('.cspr-page-input');
        const total = root.querySelector('.cspr-total');
        const zoomText = root.querySelector('.cspr-zoom-value');
        const viewportBox = root.querySelector('.cspr-canvas-wrap');
        let pdf = null, pageNum = 1, zoom = 1, renderTask = null, renderToken = 0;
        let baseScale = 1, pageAspect = 0.72, resizeTimer;

        function showError(msg) {
            status.textContent = msg;
            status.hidden = false;
            root.classList.add('cspr-has-error');
        }

        function fitScale(page) {
            const v = page.getViewport({scale:1});
            const availableW = Math.max(200, viewportBox.clientWidth - 28);
            const availableH = Math.max(250, viewportBox.clientHeight - 24);
            return Math.min(availableW / v.width, availableH / v.height);
        }

        async function renderCurrent() {
            if (!pdf) return;
            const token = ++renderToken;
            if (renderTask) {
                try { renderTask.cancel(); } catch(e) {}
                renderTask = null;
            }
            status.hidden = false;
            status.textContent = 'Rendering page ' + pageNum + '…';
            try {
                const page = await pdf.getPage(pageNum);
                if (token !== renderToken) return;

                baseScale = fitScale(page);
                const scale = baseScale * zoom;
                const dpr = Math.min(Math.max(window.devicePixelRatio || 1, 1), 2.5);
                const viewport = page.getViewport({scale: scale * dpr});

                canvas.width = Math.max(1, Math.floor(viewport.width));
                canvas.height = Math.max(1, Math.floor(viewport.height));
                canvas.style.width = Math.round(viewport.width / dpr) + 'px';
                canvas.style.height = Math.round(viewport.height / dpr) + 'px';

                // Render at device-pixel resolution, but keep the canvas responsive and scrollable when zoomed.
                renderTask = page.render({canvasContext:ctx, viewport:viewport, intent:'display', background:'#ffffff'});
                await renderTask.promise;
                if (token !== renderToken) return;
                renderTask = null;
                status.hidden = true;
                pageInput.value = pageNum;
                total.textContent = '/ ' + pdf.numPages;
                zoomText.textContent = Math.round(zoom * 100) + '%';
                root.querySelector('.cspr-prev').disabled = pageNum <= 1;
                root.querySelector('.cspr-next').disabled = pageNum >= pdf.numPages;
            } catch(e) {
                if (e && e.name === 'RenderingCancelledException') return;
                showError('This page could not be rendered. Try reloading the page or check the PDF file/URL.');
                console.error('[CS PDF Reader]', e);
            }
        }

        function goTo(n) {
            if (!pdf) return;
            pageNum = Math.max(1, Math.min(pdf.numPages, Math.floor(Number(n) || 1)));
            renderCurrent();
        }
        function setZoom(value) {
            zoom = Math.max(0.5, Math.min(3, Math.round(value * 4) / 4));
            renderCurrent();
        }

        root.querySelector('.cspr-prev').addEventListener('click', () => goTo(pageNum - 1));
        root.querySelector('.cspr-next').addEventListener('click', () => goTo(pageNum + 1));
        root.querySelector('.cspr-zoom-in').addEventListener('click', () => setZoom(zoom + 0.25));
        root.querySelector('.cspr-zoom-out').addEventListener('click', () => setZoom(zoom - 0.25));
        root.querySelector('.cspr-zoom-reset').addEventListener('click', () => setZoom(1));
        pageInput.addEventListener('change', () => goTo(pageInput.value));
        pageInput.addEventListener('keydown', e => { if (e.key === 'Enter') { goTo(pageInput.value); pageInput.blur(); } });
        root.querySelector('.cspr-fullscreen').addEventListener('click', async () => {
            try {
                if (!document.fullscreenElement) await root.requestFullscreen();
                else await document.exitFullscreen();
                setTimeout(renderCurrent, 250);
            } catch(e) { console.warn('Fullscreen unavailable', e); }
        });

        document.addEventListener('fullscreenchange', () => {
            if (document.fullscreenElement === root || !document.fullscreenElement) setTimeout(renderCurrent, 250);
        });

        if ('ResizeObserver' in window) {
            const observer = new ResizeObserver(() => {
                clearTimeout(resizeTimer);
                resizeTimer = setTimeout(() => { if (zoom === 1) renderCurrent(); }, 180);
            });
            observer.observe(viewportBox);
        } else {
            window.addEventListener('resize', () => {
                clearTimeout(resizeTimer);
                resizeTimer = setTimeout(() => { if (zoom === 1) renderCurrent(); }, 180);
            });
        }

        if (!window.pdfjsLib) { showError('PDF viewer library did not load. Please check your CDN or optimization settings.'); return; }
        pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
        pdfjsLib.getDocument({
            url: root.dataset.pdf,
            rangeChunkSize: 262144,
            disableAutoFetch: false,
            disableStream: false
        }).promise.then(doc => {
            pdf = doc;
            total.textContent = '/ ' + pdf.numPages;
            status.hidden = true;
            renderCurrent();
        }).catch(err => {
            console.error('[CS PDF Reader] Load error', err);
            showError('Unable to load PDF. Check that the URL is public and the server allows PDF access (including range/CORS requests).');
        });
    }

    function init() { document.querySelectorAll('.cspr-reader').forEach(initViewer); }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
JS;
        wp_add_inline_script('cspr-pdfjs', $js, 'after');
    }

    public function shortcode($atts) {
        $atts = shortcode_atts(['id'=>0], $atts, 'pdf_flipbook');
        $id = absint($atts['id']);
        if (!$id || get_post_type($id) !== self::CPT || get_post_status($id) !== 'publish') return '';

        $source = get_post_meta($id, self::SOURCE, true) ?: 'upload';
        if ($source === 'url') {
            $pdf_url = get_post_meta($id, self::URL, true);
        } else {
            $attachment = absint(get_post_meta($id, self::ATTACHMENT, true));
            $pdf_url = ($attachment && get_post_mime_type($attachment) === 'application/pdf') ? wp_get_attachment_url($attachment) : '';
        }
        if (!$pdf_url) return '<p class="cspr-error">PDF is not configured correctly.</p>';
        $scheme = strtolower((string)wp_parse_url($pdf_url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http','https'], true)) return '<p class="cspr-error">Invalid PDF URL.</p>';

        wp_enqueue_script('cspr-pdfjs');
        $uid = 'cspr-' . wp_unique_id();
        ob_start(); ?>
        <div id="<?php echo esc_attr($uid); ?>" class="cspr-reader" data-pdf="<?php echo esc_url($pdf_url); ?>">
            <div class="cspr-toolbar">
                <button type="button" class="cspr-prev" disabled aria-label="Previous page">‹</button>
                <label class="cspr-page-label"><span class="screen-reader-text">Page number</span><input class="cspr-page-input" type="number" min="1" value="1" inputmode="numeric"></label>
                <span class="cspr-total">/ …</span>
                <button type="button" class="cspr-next" disabled aria-label="Next page">›</button>
                <span class="cspr-separator"></span>
                <button type="button" class="cspr-zoom-out" aria-label="Zoom out">−</button>
                <span class="cspr-zoom-value">100%</span>
                <button type="button" class="cspr-zoom-in" aria-label="Zoom in">+</button>
                <button type="button" class="cspr-zoom-reset">Fit</button>
                <button type="button" class="cspr-fullscreen">Fullscreen</button>
            </div>
            <div class="cspr-canvas-wrap">
                <div class="cspr-status" role="status">Loading PDF…</div>
                <canvas aria-label="PDF page"></canvas>
            </div>
        </div>
        <style>
            .cspr-reader{width:100%;max-width:100%;margin:18px auto;border:1px solid #e3e3e3;background:#f0f0f0;font-family:Arial,sans-serif;box-sizing:border-box}
            .cspr-reader *{box-sizing:border-box}
            .cspr-toolbar{display:flex;align-items:center;justify-content:center;gap:7px;flex-wrap:wrap;padding:10px;background:#fff;border-bottom:1px solid #ddd}
            .cspr-toolbar button{min-width:36px;padding:7px 10px;border:1px solid #ccc;border-radius:4px;background:#fff;color:#222;font:14px Arial,sans-serif;cursor:pointer}
            .cspr-toolbar button:hover:not(:disabled){background:#f3f3f3}
            .cspr-toolbar button:disabled{opacity:.4;cursor:not-allowed}
            .cspr-page-input{width:54px;padding:6px 3px;border:1px solid #ccc;border-radius:4px;text-align:center;font:14px Arial,sans-serif}
            .cspr-total,.cspr-zoom-value{font-size:13px;color:#333;min-width:38px;text-align:center}
            .cspr-separator{height:24px;border-left:1px solid #ddd;margin:0 4px}
            .cspr-canvas-wrap{height:min(78vh,1050px);min-height:360px;width:100%;overflow:auto;display:flex;align-items:flex-start;justify-content:center;padding:14px;background:#ededed;position:relative;overscroll-behavior:contain}
            .cspr-canvas-wrap canvas{display:block;flex:none;max-width:none;background:#fff;box-shadow:0 1px 7px rgba(0,0,0,.18)}
            .cspr-status{position:absolute;top:12px;left:50%;transform:translateX(-50%);z-index:2;background:rgba(255,255,255,.94);padding:8px 12px;border-radius:4px;color:#333;font-size:13px;white-space:normal;text-align:center;max-width:90%}
            .cspr-status[hidden]{display:none}
            .cspr-reader:fullscreen{width:100%;height:100%;max-width:none;margin:0;border:0;display:flex;flex-direction:column;background:#222}
            .cspr-reader:fullscreen .cspr-canvas-wrap{height:auto;min-height:0;flex:1;background:#222}
            .cspr-reader:fullscreen .cspr-toolbar{flex-shrink:0}
            @media(max-width:600px){
                .cspr-toolbar{gap:5px;padding:8px 4px}
                .cspr-toolbar button{padding:7px 8px;font-size:12px}
                .cspr-page-input{width:45px}
                .cspr-separator{display:none}
                .cspr-canvas-wrap{height:70vh;min-height:300px;padding:8px}
            }
        </style>
        <?php return ob_get_clean();
    }
}
new CS_PDF_Reader();
