/**
 * Plugin Name: CS PDF Flipbook
 * Description: PDF flipbooks with upload/URL sources, individual shortcodes, sharper rendering, zoom, fullscreen and page controls.
 * Version: 2.2.0
 * Author: CS
 */

if (!defined('ABSPATH')) exit;

class CS_PDF_Flipbook {
    const CPT = 'cs_pdf_flipbook';
    const SOURCE = '_csfb_source';
    const ATTACHMENT = '_csfb_attachment';
    const URL = '_csfb_url';

    public function __construct() {
        add_action('init', [$this, 'register_cpt']);
        add_action('add_meta_boxes', [$this, 'add_metabox']);
        add_action('save_post', [$this, 'save_metabox'], 10, 2);
        add_action('admin_enqueue_scripts', [$this, 'admin_assets']);
        add_action('wp_enqueue_scripts', [$this, 'register_assets']);
        add_shortcode('pdf_flipbook', [$this, 'shortcode']);
    }

    public function register_cpt() {
        register_post_type(self::CPT, [
            'labels' => [
                'name' => 'PDF Flipbooks',
                'singular_name' => 'PDF Flipbook',
                'add_new_item' => 'Add New Flipbook',
                'edit_item' => 'Edit Flipbook',
                'menu_name' => 'PDF Flipbooks',
            ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => true,
            'menu_icon' => 'dashicons-book',
            'supports' => ['title'],
        ]);
    }

    public function add_metabox() {
        add_meta_box('csfb_settings', 'PDF Upload / URL & Shortcode', [$this, 'render_metabox'], self::CPT, 'normal', 'high');
    }

    public function render_metabox($post) {
        wp_nonce_field('csfb_save', 'csfb_nonce');
        $source = get_post_meta($post->ID, self::SOURCE, true) ?: 'upload';
        $attachment = (int) get_post_meta($post->ID, self::ATTACHMENT, true);
        $url = get_post_meta($post->ID, self::URL, true);
        $file_url = $attachment ? wp_get_attachment_url($attachment) : '';
        ?>
        <div class="csfb-admin">
            <p><strong>PDF source</strong></p>
            <label style="margin-right:18px"><input type="radio" name="csfb_source" value="upload" <?php checked($source, 'upload'); ?>> Upload / Media Library</label>
            <label><input type="radio" name="csfb_source" value="url" <?php checked($source, 'url'); ?>> External PDF URL</label>
            <hr>
            <div id="csfb-upload-panel">
                <input type="hidden" id="csfb_attachment" name="csfb_attachment" value="<?php echo esc_attr($attachment); ?>">
                <input type="text" id="csfb_file_url" value="<?php echo esc_url($file_url); ?>" readonly style="width:65%;max-width:600px">
                <button type="button" class="button" id="csfb_select">Upload / Select PDF</button>
                <button type="button" class="button" id="csfb_remove">Remove</button>
                <p class="description">Large uploads depend on server upload limits. For very large files, upload via hosting file manager/SFTP and add the PDF to Media Library.</p>
            </div>
            <div id="csfb-url-panel">
                <p><label for="csfb_url"><strong>PDF URL</strong></label></p>
                <input type="url" id="csfb_url" name="csfb_url" value="<?php echo esc_attr($url); ?>" placeholder="https://example.com/document.pdf" style="width:100%;max-width:750px">
                <p class="description">The URL must be publicly accessible. External servers may need to allow CORS requests.</p>
            </div>
            <?php if ($post->ID && get_post_status($post->ID) !== 'auto-draft'): ?>
                <hr><p><strong>Shortcode</strong></p>
                <code id="csfb_shortcode">[pdf_flipbook id="<?php echo esc_attr($post->ID); ?>"]</code>
                <button type="button" class="button" id="csfb_copy">Copy Shortcode</button>
            <?php else: ?>
                <p class="description">Save or publish this flipbook to generate its shortcode.</p>
            <?php endif; ?>
        </div>
        <?php
    }

    public function admin_assets($hook) {
        $screen = get_current_screen();
        if (!$screen || $screen->post_type !== self::CPT) return;
        wp_enqueue_media();
        wp_enqueue_script('jquery');
        $js = <<<'JS'
jQuery(function($) {
    let frame;
    function toggleSource() {
        const source = $('input[name="csfb_source"]:checked').val();
        $('#csfb-upload-panel').toggle(source === 'upload');
        $('#csfb-url-panel').toggle(source === 'url');
    }
    $('input[name="csfb_source"]').on('change', toggleSource);
    toggleSource();

    $('#csfb_select').on('click', function(e) {
        e.preventDefault();
        if (frame) { frame.open(); return; }
        frame = wp.media({
            title: 'Select or Upload PDF',
            button: { text: 'Use this PDF' },
            library: { type: 'application/pdf' },
            multiple: false
        });
        frame.on('select', function() {
            const file = frame.state().get('selection').first().toJSON();
            if (file.mime !== 'application/pdf' && !/\.pdf($|\?)/i.test(file.url)) {
                alert('Please select a PDF file.');
                return;
            }
            $('#csfb_attachment').val(file.id);
            $('#csfb_file_url').val(file.url);
        });
        frame.open();
    });
    $('#csfb_remove').on('click', function() {
        $('#csfb_attachment').val('');
        $('#csfb_file_url').val('');
    });
    $('#csfb_copy').on('click', async function() {
        const value = $('#csfb_shortcode').text();
        try { await navigator.clipboard.writeText(value); }
        catch(e) {
            const input = $('<textarea>').val(value).appendTo('body');
            input[0].select(); document.execCommand('copy'); input.remove();
        }
        const button = $(this); button.text('Copied!');
        setTimeout(() => button.text('Copy Shortcode'), 1500);
    });
});
JS;
        wp_add_inline_script('jquery', $js);
    }

    public function save_metabox($post_id, $post) {
        if ($post->post_type !== self::CPT || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || wp_is_post_revision($post_id)) return;
        if (!isset($_POST['csfb_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['csfb_nonce'])), 'csfb_save')) return;
        if (!current_user_can('edit_post', $post_id)) return;

        $source = isset($_POST['csfb_source']) ? sanitize_key(wp_unslash($_POST['csfb_source'])) : 'upload';
        if (!in_array($source, ['upload', 'url'], true)) $source = 'upload';
        update_post_meta($post_id, self::SOURCE, $source);

        if ($source === 'upload') {
            $attachment = isset($_POST['csfb_attachment']) ? absint($_POST['csfb_attachment']) : 0;
            if ($attachment && get_post_mime_type($attachment) === 'application/pdf') update_post_meta($post_id, self::ATTACHMENT, $attachment);
            else delete_post_meta($post_id, self::ATTACHMENT);
            delete_post_meta($post_id, self::URL);
        } else {
            $url = isset($_POST['csfb_url']) ? esc_url_raw(trim(wp_unslash($_POST['csfb_url']))) : '';
            $scheme = $url ? wp_parse_url($url, PHP_URL_SCHEME) : '';
            if ($url && in_array(strtolower((string)$scheme), ['http', 'https'], true)) update_post_meta($post_id, self::URL, $url);
            else delete_post_meta($post_id, self::URL);
            delete_post_meta($post_id, self::ATTACHMENT);
        }
    }

    public function register_assets() {
        wp_register_script('csfb-pdfjs', 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js', [], '3.11.174', true);
        wp_register_script('csfb-pageflip', 'https://cdn.jsdelivr.net/npm/page-flip@2.0.7/dist/js/page-flip.browser.min.js', [], '2.0.7', true);

        $js = <<<'JS'
(function() {
    function initOne(root) {
        if (root.dataset.initialized) return;
        root.dataset.initialized = '1';

        const stage = root.querySelector('.csfb-stage');
        const loading = root.querySelector('.csfb-loading');
        const counter = root.querySelector('.csfb-counter');
        const pdfUrl = root.dataset.pdf;
        const zoomLabel = root.querySelector('.csfb-zoom-label');
        let pdf, flip, pages = [], rendered = new Set(), jobs = new Map();
        let zoom = 1, generation = 0, rendering = 0;
        const MAX_CONCURRENT = 2;

        function error(message) {
            loading.textContent = message;
            loading.style.display = 'block';
            console.error('[CS PDF Flipbook]', message);
        }

        function visiblePages() {
            if (!flip || !pdf) return [];
            const current = flip.getCurrentPageIndex() + 1;
            const count = flip.getOrientation() === 'portrait' ? 1 : 2;
            const out = [];
            for (let n = Math.max(1, current - 1); n <= Math.min(pdf.numPages, current + count); n++) out.push(n);
            return out;
        }

        async function renderPage(n, force) {
            if (!pdf || n < 1 || n > pdf.numPages) return;
            if (!force && rendered.has(n)) return;
            if (jobs.has(n)) return jobs.get(n);

            const gen = generation;
            const task = (async function() {
                while (rendering >= MAX_CONCURRENT) await new Promise(resolve => setTimeout(resolve, 30));
                rendering++;
                try {
                    const page = await pdf.getPage(n);
                    const el = pages[n - 1];
                    const canvas = el.querySelector('canvas');
                    const ctx = canvas.getContext('2d', { alpha: false });

                    // PageFlip can change its page box after orientation/fullscreen.
                    const box = el.getBoundingClientRect();
                    const cssW = Math.max(1, box.width);
                    const cssH = Math.max(1, box.height);
                    const natural = page.getViewport({ scale: 1 });
                    const fit = Math.min(cssW / natural.width, cssH / natural.height);

                    // Zoom is for readable inspection; quality oversampling is separate.
                    const dpr = Math.min(Math.max(window.devicePixelRatio || 1, 1), 2);
                    const outputScale = Math.min(fit * zoom * dpr * 2.5, 5);
                    const viewport = page.getViewport({ scale: outputScale });

                    // Set intrinsic pixel dimensions, while CSS keeps the PDF aspect ratio.
                    const cssScale = Math.min(cssW / natural.width, cssH / natural.height) * zoom;
                    canvas.width = Math.max(1, Math.ceil(viewport.width));
                    canvas.height = Math.max(1, Math.ceil(viewport.height));
                    canvas.style.width = (natural.width * cssScale) + 'px';
                    canvas.style.height = (natural.height * cssScale) + 'px';
                    canvas.style.maxWidth = 'none';
                    canvas.style.maxHeight = 'none';

                    // At zoom > 1 the page is intentionally clipped to the viewer window.
                    await page.render({
                        canvasContext: ctx,
                        viewport: viewport,
                        background: '#ffffff',
                        intent: 'display'
                    }).promise;

                    if (gen === generation) rendered.add(n);
                } finally {
                    rendering--;
                }
            })();

            jobs.set(n, task);
            try { await task; }
            finally { jobs.delete(n); }
        }

        async function renderVisible(force) {
            const list = visiblePages();
            await Promise.all(list.map(n => renderPage(n, !!force)));
        }

        function updateUI() {
            if (flip && pdf) counter.textContent = 'Page ' + (flip.getCurrentPageIndex() + 1) + ' / ' + pdf.numPages;
            zoomLabel.textContent = Math.round(zoom * 100) + '%';
        }

        function setZoom(next) {
            zoom = Math.max(1, Math.min(2.5, Math.round(next * 10) / 10));
            generation++;
            rendered.clear();
            // Cancel queued state only; in-progress PDF.js renders finish safely.
            updateUI();
            renderVisible(true);
        }

        async function start() {
            try {
                if (!window.pdfjsLib) throw new Error('PDF.js failed to load. Check CDN/cache settings.');
                if (!window.St || !window.St.PageFlip) throw new Error('PageFlip failed to load.');
                pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';

                pdf = await pdfjsLib.getDocument({
                    url: pdfUrl,
                    rangeChunkSize: 262144,
                    disableAutoFetch: false,
                    disableStream: false
                }).promise;

                const first = await pdf.getPage(1);
                const firstViewport = first.getViewport({ scale: 1 });
                const ratio = firstViewport.width / firstViewport.height;

                const fragment = document.createDocumentFragment();
                for (let i = 1; i <= pdf.numPages; i++) {
                    const el = document.createElement('div');
                    el.className = 'csfb-page';
                    el.dataset.page = i;
                    const canvas = document.createElement('canvas');
                    el.appendChild(canvas);
                    fragment.appendChild(el);
                    pages.push(el);
                }
                stage.appendChild(fragment);

                flip = new St.PageFlip(stage, {
                    width: Math.max(300, Math.round(520 * ratio)),
                    height: 680,
                    size: 'stretch',
                    minWidth: 260,
                    maxWidth: 1200,
                    minHeight: 320,
                    maxHeight: 1500,
                    showCover: true,
                    usePortrait: true,
                    mobileScrollSupport: false,
                    drawShadow: true,
                    maxShadowOpacity: 0.3,
                    flippingTime: 650,
                    useMouseEvents: true
                });
                flip.loadFromHTML(pages);

                flip.on('flip', function() { updateUI(); renderVisible(false); });
                flip.on('changeOrientation', function() {
                    generation++; rendered.clear(); updateUI(); renderVisible(true);
                });

                root.querySelector('.csfb-prev').addEventListener('click', () => flip && flip.flipPrev());
                root.querySelector('.csfb-next').addEventListener('click', () => flip && flip.flipNext());
                root.querySelector('.csfb-zoom-in').addEventListener('click', () => setZoom(zoom + 0.25));
                root.querySelector('.csfb-zoom-out').addEventListener('click', () => setZoom(zoom - 0.25));
                root.querySelector('.csfb-zoom-reset').addEventListener('click', () => setZoom(1));
                root.querySelector('.csfb-fullscreen').addEventListener('click', async function() {
                    try {
                        if (!document.fullscreenElement) await root.requestFullscreen();
                        else await document.exitFullscreen();
                        setTimeout(() => { generation++; rendered.clear(); renderVisible(true); }, 300);
                    } catch(e) { console.error('[CS PDF Flipbook] Fullscreen:', e); }
                });

                loading.style.display = 'none';
                updateUI();
                await renderVisible(true);

                let timer;
                const resizeObserver = new ResizeObserver(function() {
                    clearTimeout(timer);
                    timer = setTimeout(function() {
                        generation++; rendered.clear(); updateUI(); renderVisible(true);
                    }, 350);
                });
                resizeObserver.observe(root);
                document.addEventListener('fullscreenchange', function() {
                    setTimeout(function() {
                        generation++; rendered.clear(); renderVisible(true);
                    }, 300);
                });
            } catch(e) {
                error('Unable to load PDF: ' + (e && e.message ? e.message : 'Unknown error'));
            }
        }
        start();
    }

    function init() { document.querySelectorAll('.csfb-viewer').forEach(initOne); }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
JS;
        wp_add_inline_script('csfb-pageflip', $js, 'after');
    }

    public function shortcode($atts) {
        $atts = shortcode_atts(['id' => 0], $atts, 'pdf_flipbook');
        $id = absint($atts['id']);
        if (!$id || get_post_type($id) !== self::CPT || get_post_status($id) !== 'publish') return '';

        $source = get_post_meta($id, self::SOURCE, true) ?: 'upload';
        if ($source === 'url') {
            $pdf_url = get_post_meta($id, self::URL, true);
        } else {
            $attachment = (int) get_post_meta($id, self::ATTACHMENT, true);
            $pdf_url = ($attachment && get_post_mime_type($attachment) === 'application/pdf') ? wp_get_attachment_url($attachment) : '';
        }
        if (!$pdf_url) return '<p class="csfb-error">PDF is not configured correctly.</p>';
        $scheme = wp_parse_url($pdf_url, PHP_URL_SCHEME);
        if (!in_array(strtolower((string)$scheme), ['http', 'https'], true)) return '<p class="csfb-error">Invalid PDF URL.</p>';

        wp_enqueue_script('csfb-pdfjs');
        wp_enqueue_script('csfb-pageflip');
        $viewer_id = 'csfb-' . wp_unique_id();
        ob_start();
        ?>
        <div id="<?php echo esc_attr($viewer_id); ?>" class="csfb-viewer" data-pdf="<?php echo esc_url($pdf_url); ?>">
            <div class="csfb-loading">Loading PDF flipbook...</div>
            <div class="csfb-stage-wrap"><div class="csfb-stage"></div></div>
            <div class="csfb-controls">
                <button type="button" class="csfb-prev" aria-label="Previous page">‹</button>
                <span class="csfb-counter">Page 0 / 0</span>
                <button type="button" class="csfb-next" aria-label="Next page">›</button>
                <span class="csfb-control-divider"></span>
                <button type="button" class="csfb-zoom-out" aria-label="Zoom out">−</button>
                <span class="csfb-zoom-label">100%</span>
                <button type="button" class="csfb-zoom-in" aria-label="Zoom in">+</button>
                <button type="button" class="csfb-zoom-reset">Reset zoom</button>
                <button type="button" class="csfb-fullscreen">Fullscreen</button>
                <a class="csfb-download" href="<?php echo esc_url($pdf_url); ?>" target="_blank" rel="noopener noreferrer">Download PDF</a>
            </div>
        </div>
        <style>
            .csfb-viewer{width:100%;max-width:1200px;margin:20px auto;text-align:center;font-family:Arial,sans-serif;box-sizing:border-box}
            .csfb-viewer *{box-sizing:border-box}
            .csfb-stage-wrap{width:100%;height:clamp(360px,65vw,760px);min-height:300px;overflow:hidden;background:#eee;position:relative;touch-action:pan-y}
            .csfb-stage{width:100%;height:100%;margin:auto;overflow:hidden}
            .csfb-page{display:flex;align-items:center;justify-content:center;overflow:hidden;background:#fff}
            .csfb-page canvas{display:block;flex:none;object-fit:contain;image-rendering:auto}
            .csfb-loading{padding:16px;color:#333}
            .csfb-controls{display:flex;justify-content:center;align-items:center;flex-wrap:wrap;gap:8px;padding:12px}
            .csfb-controls button,.csfb-controls a{border:1px solid #ccc;border-radius:4px;padding:8px 12px;background:#fff;color:#222;cursor:pointer;text-decoration:none;font:14px/1.4 Arial,sans-serif}
            .csfb-controls button:hover,.csfb-controls a:hover{background:#f1f1f1}
            .csfb-zoom-label{min-width:48px;font-size:13px;color:#333}
            .csfb-control-divider{height:24px;border-left:1px solid #ccc;margin:0 3px}
            .csfb-viewer:fullscreen{background:#222;width:100%;height:100%;max-width:none;margin:0;display:flex;flex-direction:column;justify-content:center}
            .csfb-viewer:fullscreen .csfb-stage-wrap{height:calc(100vh - 75px);max-height:none;width:100%;background:#222}
            .csfb-viewer:fullscreen .csfb-controls{background:#222}
            .csfb-viewer:fullscreen .csfb-controls button,.csfb-viewer:fullscreen .csfb-controls a{background:#fff}
            @media(max-width:600px){
                .csfb-stage-wrap{height:100vw;min-height:260px}
                .csfb-controls{gap:5px;padding:8px 2px}
                .csfb-controls button,.csfb-controls a{padding:7px 9px;font-size:12px}
                .csfb-control-divider{display:none}
            }
        </style>
        <?php
        return ob_get_clean();
    }
}

new CS_PDF_Flipbook();
