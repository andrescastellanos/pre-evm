<?php
/**
 * Plugin Name: Viva Pre-EVM
 * Plugin URI:  https://vivaaustralia.com.co
 * Description: Test de Pre-Evaluación Migratoria — integrado con GoHighLevel CRM y Anthropic AI
 * Version:     4.0.0
 * Author:      Viva Australia Internacional
 * Text Domain: viva-pre-evm
 * License:     GPL2
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ═══════════════════════════════════════════════════════════════
// CONSTANTES
// ═══════════════════════════════════════════════════════════════
define( 'VIVA_PREEVM_VER', '4.0.0' );
define( 'GHL_API_KEY',     'pit-52bd3a9b-0327-451b-b56b-77cd3546083e' );
define( 'GHL_LOCATION_ID', 'eAmbGQl2QpcHDwBWM7s6' );
define( 'GHL_BASE_URL',    'https://services.leadconnectorhq.com' );

// ═══════════════════════════════════════════════════════════════
// HOOKS
// ═══════════════════════════════════════════════════════════════
register_activation_hook( __FILE__, function () {
    viva_preevm_register_cpts();
    flush_rewrite_rules();
} );

add_action( 'init',               'viva_preevm_register_cpts'        );
add_action( 'rest_api_init',      'viva_preevm_register_rest_routes' );
add_action( 'admin_menu',         'viva_preevm_admin_menu'           );
add_action( 'admin_init',         'viva_preevm_settings_init'        );
add_action( 'wp_enqueue_scripts', 'viva_preevm_enqueue'              );
add_shortcode( 'viva_pre_evm',    'viva_preevm_shortcode'            );
add_filter( 'the_content',        'viva_preevm_result_content'       );

// ═══════════════════════════════════════════════════════════════
// ENQUEUE
// ═══════════════════════════════════════════════════════════════
function viva_preevm_enqueue() {
    wp_enqueue_script(
        'jspdf',
        'https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js',
        [], '2.5.1', true
    );
    wp_enqueue_style(
        'viva-preevm-fonts',
        'https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,600;0,700;1,400;1,600&family=Outfit:wght@300;400;500;600;700;800&display=swap',
        [], null
    );
}

// ═══════════════════════════════════════════════════════════════
// SETTINGS
// ═══════════════════════════════════════════════════════════════
function viva_preevm_admin_menu() {
    add_options_page( 'Viva Pre-EVM', 'Viva Pre-EVM', 'manage_options', 'viva-pre-evm', 'viva_preevm_settings_page' );
}

function viva_preevm_settings_init() {
    $sf = [ 'sanitize_callback' => 'sanitize_text_field' ];
    register_setting( 'viva_preevm', 'viva_ai_provider',    $sf );
    register_setting( 'viva_preevm', 'viva_anthropic_key',  $sf );
    register_setting( 'viva_preevm', 'viva_anthropic_model',$sf );
    register_setting( 'viva_preevm', 'viva_openai_key',     $sf );
    register_setting( 'viva_preevm', 'viva_openai_model',   $sf );
    register_setting( 'viva_preevm', 'viva_gemini_key',     $sf );
    register_setting( 'viva_preevm', 'viva_gemini_model',   $sf );
    register_setting( 'viva_preevm', 'viva_ghl_key',        array_merge( $sf, [ 'default' => GHL_API_KEY ] ) );
}

function viva_preevm_settings_page() {
    if ( ! current_user_can( 'manage_options' ) ) return;

    if ( isset( $_POST['viva_save'] ) ) {
        check_admin_referer( 'viva_preevm_save' );
        $fields = [
            'viva_ai_provider', 'viva_anthropic_key', 'viva_anthropic_model',
            'viva_openai_key', 'viva_openai_model',
            'viva_gemini_key', 'viva_gemini_model',
            'viva_ghl_key',
        ];
        foreach ( $fields as $f ) {
            update_option( $f, sanitize_text_field( $_POST[ $f ] ?? '' ) );
        }
        echo '<div class="notice notice-success is-dismissible"><p>&#10003; Configuraci&oacute;n guardada.</p></div>';
    }

    // Leer opciones actuales
    $provider   = get_option( 'viva_ai_provider',     'anthropic' );
    $ant_key    = esc_attr( get_option( 'viva_anthropic_key',   '' ) );
    $ant_model  = esc_attr( get_option( 'viva_anthropic_model', 'claude-sonnet-4-20250514' ) );
    $oai_key    = esc_attr( get_option( 'viva_openai_key',      '' ) );
    $oai_model  = esc_attr( get_option( 'viva_openai_model',    'gpt-4o' ) );
    $gem_key    = esc_attr( get_option( 'viva_gemini_key',      '' ) );
    $gem_model  = esc_attr( get_option( 'viva_gemini_model',    'gemini-2.0-flash' ) );
    $ghl_key    = esc_attr( get_option( 'viva_ghl_key',         GHL_API_KEY ) );

    // Metadatos de cada proveedor (label, modelos, costo est., URL docs)
    $providers = [
        'anthropic' => [
            'label' => 'Anthropic Claude',
            'icon'  => '🟣',
            'docs'  => 'https://console.anthropic.com',
            'hint'  => 'console.anthropic.com',
            'cv_pdf'=> true,
            'models'=> [
                'claude-sonnet-4-20250514' => 'Claude Sonnet 4.6 — recomendado (~$0.003/análisis)',
                'claude-opus-4-5'          => 'Claude Opus 4.5 — más preciso (~$0.015/análisis)',
                'claude-haiku-4-5-20251001'=> 'Claude Haiku 4.5 — más rápido (~$0.0003/análisis)',
                'claude-sonnet-3-7-20250219'=> 'Claude Sonnet 3.7',
            ],
        ],
        'openai' => [
            'label' => 'OpenAI',
            'icon'  => '🟢',
            'docs'  => 'https://platform.openai.com/api-keys',
            'hint'  => 'platform.openai.com',
            'cv_pdf'=> false,
            'models'=> [
                'gpt-4o'       => 'GPT-4o — recomendado (~$0.005/análisis)',
                'gpt-4o-mini'  => 'GPT-4o Mini — económico (~$0.0003/análisis)',
                'gpt-4.1'      => 'GPT-4.1',
                'gpt-4.1-mini' => 'GPT-4.1 Mini',
                'gpt-4-turbo'  => 'GPT-4 Turbo',
            ],
        ],
        'gemini' => [
            'label' => 'Google Gemini',
            'icon'  => '🔵',
            'docs'  => 'https://aistudio.google.com/app/apikey',
            'hint'  => 'aistudio.google.com',
            'cv_pdf'=> true,
            'models'=> [
                'gemini-2.0-flash'     => 'Gemini 2.0 Flash — recomendado (~$0.0001/análisis)',
                'gemini-2.5-pro-preview-03-25' => 'Gemini 2.5 Pro (~$0.010/análisis)',
                'gemini-1.5-pro'       => 'Gemini 1.5 Pro',
                'gemini-1.5-flash'     => 'Gemini 1.5 Flash — económico',
            ],
        ],
    ];

    $active_label = $providers[ $provider ]['label'] ?? 'Anthropic Claude';
    $active_icon  = $providers[ $provider ]['icon']  ?? '🟣';
    ?>
    <div class="wrap">
    <h1>&#9881; Viva Pre-EVM &mdash; Configuraci&oacute;n</h1>

    <form method="post">
    <?php wp_nonce_field( 'viva_preevm_save' ); ?>

    <!-- ── BLOQUE: PROVEEDOR DE IA ── -->
    <div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:24px;margin-bottom:24px;max-width:860px">
      <h2 style="margin-top:0;font-size:16px">🤖 Proveedor de IA para el an&aacute;lisis</h2>
      <p style="color:#666;margin-bottom:16px">Elige qu&eacute; IA usa el endpoint <code>/analyze</code>. Solo el proveedor activo se utiliza; las dem&aacute;s keys se guardan para poder switchear f&aacute;cilmente.</p>

      <!-- Selector de proveedor (botones de radio estilo tab) -->
      <div style="display:flex;gap:10px;margin-bottom:24px;flex-wrap:wrap" id="viva-provider-tabs">
        <?php foreach ( $providers as $slug => $info ) : ?>
        <label style="cursor:pointer">
          <input type="radio" name="viva_ai_provider" value="<?php echo esc_attr( $slug ); ?>"
                 <?php checked( $provider, $slug ); ?>
                 onchange="vivaShowProvider('<?php echo esc_js( $slug ); ?>')"
                 style="display:none">
          <span class="viva-tab-btn" id="vtab-<?php echo esc_attr( $slug ); ?>"
                style="display:inline-flex;align-items:center;gap:6px;padding:10px 18px;border-radius:8px;border:2px solid;font-size:14px;font-weight:600;transition:all .2s;cursor:pointer;
                <?php echo $provider === $slug
                    ? 'background:#f0f0ff;border-color:#5865f2;color:#5865f2'
                    : 'background:#f9f9f9;border-color:#ddd;color:#555'; ?>">
            <?php echo $info['icon']; ?> <?php echo esc_html( $info['label'] ); ?>
            <?php
            $key_opt = 'viva_' . $slug . '_key';
            if ( $slug === 'anthropic' ) $key_opt = 'viva_anthropic_key';
            $has_key = ! empty( get_option( $key_opt, '' ) );
            echo $has_key ? ' <span style="color:#22c55e;font-size:11px">✓ key</span>' : ' <span style="color:#ef4444;font-size:11px">sin key</span>';
            ?>
          </span>
        </label>
        <?php endforeach; ?>
      </div>

      <!-- Panel de cada proveedor -->
      <?php foreach ( $providers as $slug => $info ) :
        $fk    = 'viva_' . $slug . '_key';    if ( $slug === 'anthropic' ) $fk = 'viva_anthropic_key';
        $fm    = 'viva_' . $slug . '_model';  if ( $slug === 'anthropic' ) $fm = 'viva_anthropic_model';
        $cur_k = esc_attr( get_option( $fk, '' ) );
        $cur_m = esc_attr( get_option( $fm, array_key_first( $info['models'] ) ) );
      ?>
      <div id="vpanel-<?php echo esc_attr( $slug ); ?>"
           style="<?php echo $provider !== $slug ? 'display:none;' : ''; ?>border-top:1px solid #eee;padding-top:16px">
        <table class="form-table" style="margin:0">
          <tr>
            <th style="width:180px"><label for="<?php echo esc_attr( $fk ); ?>">API Key</label></th>
            <td>
              <input type="password" id="<?php echo esc_attr( $fk ); ?>" name="<?php echo esc_attr( $fk ); ?>"
                     value="<?php echo $cur_k; ?>" class="regular-text" autocomplete="new-password">
              <p class="description">Obt&eacute;n tu key en
                <a href="<?php echo esc_url( $info['docs'] ); ?>" target="_blank"><?php echo esc_html( $info['hint'] ); ?></a>
              </p>
              <?php if ( empty( $cur_k ) ) : ?>
                <p style="color:#ef4444;font-size:12px;margin-top:4px">&#9888; Sin API key — este proveedor no funcionar&aacute;.</p>
              <?php else : ?>
                <p style="color:#22c55e;font-size:12px;margin-top:4px">&#10003; API key configurada.</p>
              <?php endif; ?>
              <?php if ( ! $info['cv_pdf'] ) : ?>
                <p style="color:#f59e0b;font-size:12px;margin-top:4px">&#9432; Este proveedor no lee PDFs nativamente &mdash; el CV se env&iacute;a como texto.</p>
              <?php endif; ?>
            </td>
          </tr>
          <tr>
            <th><label for="<?php echo esc_attr( $fm ); ?>">Modelo</label></th>
            <td>
              <select id="<?php echo esc_attr( $fm ); ?>" name="<?php echo esc_attr( $fm ); ?>" style="min-width:340px">
                <?php foreach ( $info['models'] as $mval => $mlabel ) : ?>
                  <option value="<?php echo esc_attr( $mval ); ?>" <?php selected( $cur_m, $mval ); ?>>
                    <?php echo esc_html( $mlabel ); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </td>
          </tr>
        </table>
      </div>
      <?php endforeach; ?>

      <!-- Badge proveedor activo -->
      <div style="margin-top:16px;padding:10px 16px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;font-size:13px">
        <strong>Proveedor activo ahora:</strong>
        <span id="viva-active-label"><?php echo esc_html( $active_icon . ' ' . $active_label ); ?></span>
        &mdash;
        <span id="viva-active-model" style="color:#666">
          <?php
          $am = 'viva_' . $provider . '_model'; if ( $provider === 'anthropic' ) $am = 'viva_anthropic_model';
          echo esc_html( get_option( $am, '' ) );
          ?>
        </span>
      </div>
    </div>

    <!-- ── BLOQUE: GOHIGHLEVEL ── -->
    <div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:24px;margin-bottom:24px;max-width:860px">
      <h2 style="margin-top:0;font-size:16px">&#x1F4CA; GoHighLevel CRM</h2>
      <table class="form-table" style="margin:0">
        <tr>
          <th style="width:180px"><label for="viva_ghl_key">API Key GHL</label></th>
          <td>
            <input type="password" id="viva_ghl_key" name="viva_ghl_key" value="<?php echo $ghl_key; ?>" class="regular-text" autocomplete="new-password">
            <p class="description">Private Integration Token</p>
          </td>
        </tr>
        <tr>
          <th>Location ID</th>
          <td><code><?php echo esc_html( GHL_LOCATION_ID ); ?></code></td>
        </tr>
      </table>
    </div>

    <?php submit_button( 'Guardar configuraci&oacute;n', 'primary', 'viva_save' ); ?>
    </form>

    <!-- ── INFO: Shortcode + GHL fields ── -->
    <div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:24px;max-width:860px">
      <h2 style="margin-top:0;font-size:16px">&#x2139;&#xFE0F; Informaci&oacute;n de uso</h2>
      <p><strong>Shortcode:</strong> <code>[viva_pre_evm]</code></p>
      <h3 style="font-size:14px">Custom Fields a crear en GHL (Settings &rsaquo; Custom Fields)</h3>
      <table class="widefat" style="max-width:620px">
        <thead><tr><th>Nombre</th><th>Key</th><th>Tipo</th></tr></thead>
        <tbody>
          <tr><td>Link Pre-EVM Continuar</td><td><code>preevm_continue_link</code></td><td>Text</td></tr>
          <tr><td>Link Resultado Pre-EVM</td><td><code>preevm_result_link</code></td><td>Text</td></tr>
          <tr><td>Puntaje Pre-EVM</td><td><code>preevm_score</code></td><td>Number</td></tr>
          <tr><td>Viabilidad Pre-EVM</td><td><code>preevm_viability</code></td><td>Text</td></tr>
          <tr><td>Decisi&oacute;n Pre-EVM</td><td><code>preevm_decision</code></td><td>Number</td></tr>
        </tbody>
      </table>
    </div>

    <script>
    var vpProviders = {
      anthropic: { icon:'🟣', label:'Anthropic Claude' },
      openai:    { icon:'🟢', label:'OpenAI' },
      gemini:    { icon:'🔵', label:'Google Gemini' }
    };
    function vivaShowProvider(slug) {
      ['anthropic','openai','gemini'].forEach(function(s){
        var panel = document.getElementById('vpanel-'+s);
        var tab   = document.getElementById('vtab-'+s);
        if (!panel || !tab) return;
        if (s === slug) {
          panel.style.display = '';
          tab.style.cssText = 'display:inline-flex;align-items:center;gap:6px;padding:10px 18px;border-radius:8px;border:2px solid;font-size:14px;font-weight:600;background:#f0f0ff;border-color:#5865f2;color:#5865f2;cursor:pointer;transition:all .2s';
        } else {
          panel.style.display = 'none';
          tab.style.cssText = 'display:inline-flex;align-items:center;gap:6px;padding:10px 18px;border-radius:8px;border:2px solid;font-size:14px;font-weight:600;background:#f9f9f9;border-color:#ddd;color:#555;cursor:pointer;transition:all .2s';
        }
      });
      var p = vpProviders[slug] || {};
      var lbl = document.getElementById('viva-active-label');
      var mdl = document.getElementById('viva-active-model');
      if (lbl) lbl.textContent = (p.icon||'') + ' ' + (p.label||slug);
      if (mdl) {
        var mSel = document.getElementById('viva_'+slug+'_model') || document.getElementById('viva_anthropic_model');
        if (mSel) mdl.textContent = mSel.value;
      }
    }
    // Actualizar badge de modelo al cambiar el select
    ['anthropic','openai','gemini'].forEach(function(s){
      var sel = document.getElementById('viva_'+s+'_model');
      if (!sel) { sel = document.getElementById('viva_anthropic_model'); }
      if (sel) sel.addEventListener('change', function(){
        var activeRadio = document.querySelector('input[name="viva_ai_provider"]:checked');
        if (activeRadio && activeRadio.value === s) {
          var mdl = document.getElementById('viva-active-model');
          if (mdl) mdl.textContent = this.value;
        }
      });
    });
    </script>
    </div>
    <?php
}

// ═══════════════════════════════════════════════════════════════
// CUSTOM POST TYPES
// ═══════════════════════════════════════════════════════════════
function viva_preevm_register_cpts() {
    register_post_type( 'preevm_result', [
        'public'             => true,
        'publicly_queryable' => true,
        'show_ui'            => true,
        'show_in_menu'       => true,
        'capability_type'    => 'post',
        'has_archive'        => false,
        'rewrite'            => [ 'slug' => 'preevm-resultado' ],
        'supports'           => [ 'title' ],
        'labels'             => [
            'name'          => 'Resultados Pre-EVM',
            'singular_name' => 'Resultado Pre-EVM',
            'menu_name'     => 'Pre-EVM',
        ],
    ] );
    register_post_type( 'preevm_draft', [
        'public'          => false,
        'show_ui'         => false,
        'capability_type' => 'post',
        'supports'        => [ 'title' ],
    ] );
}

// Renderizar resultado en la página del CPT
function viva_preevm_result_content( $content ) {
    if ( ! is_singular( 'preevm_result' ) ) return $content;
    $pid  = get_the_ID();
    $json = get_post_meta( $pid, '_preevm_resultado_json', true );
    if ( empty( $json ) ) return $content;
    $r    = json_decode( $json, true );
    if ( ! $r ) return $content;
    $r['nom']  = esc_html( get_post_meta( $pid, '_preevm_nombre',   true ) );
    $r['ape']  = esc_html( get_post_meta( $pid, '_preevm_apellido', true ) );
    $r['pais'] = esc_html( get_post_meta( $pid, '_preevm_pais',     true ) );
    $r['edad'] = esc_html( get_post_meta( $pid, '_preevm_edad',     true ) );
    $r['eng']  = esc_html( get_post_meta( $pid, '_preevm_ingles',   true ) );
    $r['prof'] = esc_html( get_post_meta( $pid, '_preevm_profesion',true ) );
    ob_start();
    viva_render_result_page( $r );
    return ob_get_clean();
}

function viva_render_result_page( $r ) {
    $viability = $r['viability'] ?? 'no-apto';
    $nom       = esc_html( $r['nom'] ?? '' );
    $ape       = esc_html( $r['ape'] ?? '' );
    $prof      = esc_html( $r['prof'] ?? '' );
    $pais      = esc_html( $r['pais'] ?? '' );
    $edad      = esc_html( $r['edad'] ?? '' );
    $eng       = esc_html( $r['eng'] ?? '' );
    $pts       = absint( $r['pts'] ?? 0 );
    $viaPct    = absint( $r['viaPct'] ?? 0 );
    $compPct   = absint( $r['compPct'] ?? 0 );
    $anzsco      = is_array( $r['anzsco'] ?? null ) ? $r['anzsco'] : [];
    $visas       = is_array( $r['visas'] ?? null ) ? $r['visas'] : [];
    $variables   = is_array( $r['variables'] ?? null ) ? $r['variables'] : [];
    $recom       = is_array( $r['recomendaciones'] ?? null ) ? $r['recomendaciones'] : [];
    $bloq        = is_array( $r['bloqueantes'] ?? null ) ? $r['bloqueantes'] : [];
    $shortage_map = is_array( $r['shortageMap'] ?? null ) ? $r['shortageMap'] : [];
    $desglose  = is_array( $r['desglosePuntos'] ?? null ) ? $r['desglosePuntos'] : [];
    $icons_map = [
        'cake'=>'🎂','speech'=>'🗣️','briefcase'=>'💼','clipboard'=>'📋','grad'=>'🎓',
        'target'=>'🎯','star'=>'⭐','check'=>'✅','warning'=>'⚠️','book'=>'📚',
        'chart'=>'📊','pin'=>'📍','rocket'=>'🚀','key'=>'🔑','time'=>'⏰',
        'X'=>'⚡','x'=>'⚡',
    ];
    ?>
    <style>
    @import url('https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;700&family=Cormorant+Garamond:wght@400;600&display=swap');
    .vp-sa{font-family:'Outfit',sans-serif;background:#07111F;color:#fff;padding:40px 20px;border-radius:16px;max-width:820px;margin:0 auto;line-height:1.6}
    .vp-sa *{box-sizing:border-box}
    .vp-sa .disc{background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.2);border-radius:12px;padding:16px;font-size:13px;color:rgba(255,255,255,.7);line-height:1.6;margin-bottom:20px}
    .vp-sa .meta{display:flex;gap:16px;flex-wrap:wrap;margin-bottom:20px;font-size:13px;color:#7A8EA8}
    .vp-sa .meta strong{color:#fff}
    .vp-sa .verdict{display:flex;align-items:center;gap:16px;padding:20px 24px;border-radius:14px;margin-bottom:24px}
    .vp-sa .verdict.apto{background:rgba(15,190,124,.12);border:1px solid rgba(15,190,124,.3)}
    .vp-sa .verdict.parcial,.vp-sa .verdict.no-apto{background:rgba(245,158,11,.1);border:1px solid rgba(245,158,11,.3)}
    .vp-sa .scores{display:grid;grid-template-columns:repeat(3,1fr);gap:13px;margin-bottom:24px}
    .vp-sa .sc{background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.07);border-radius:12px;padding:16px}
    .vp-sa .sc-lbl{font-size:11px;color:#7A8EA8;text-transform:uppercase;letter-spacing:.8px;margin-bottom:8px}
    .vp-sa .sc-val{font-size:24px;font-weight:700;margin-bottom:8px}
    .vp-sa .bar{height:4px;background:rgba(255,255,255,.08);border-radius:2px;overflow:hidden}
    .vp-sa .bar-f{height:100%;border-radius:2px}
    .vp-sa .sec{margin-bottom:18px;padding:20px;background:rgba(255,255,255,.035);border:1px solid rgba(255,255,255,.07);border-radius:14px}
    .vp-sa .sec-h{font-weight:700;font-size:15px;margin-bottom:10px;display:flex;align-items:center;gap:8px}
    .vp-sa .sec-num{width:26px;height:26px;border-radius:50%;background:rgba(232,96,10,.15);border:1px solid rgba(232,96,10,.3);color:#E8600A;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;flex-shrink:0;line-height:1}
    .vp-sa .sec-body{font-size:14px;color:rgba(255,255,255,.75);line-height:1.75}
    .vp-sa .az-item{display:flex;align-items:flex-start;gap:12px;padding:12px 0;border-bottom:1px solid rgba(255,255,255,.06)}
    .vp-sa .az-item:last-child{border-bottom:none}
    .vp-sa .az-code{background:rgba(232,96,10,.15);border:1px solid rgba(232,96,10,.3);color:#E8600A;padding:4px 10px;border-radius:6px;font-size:12px;font-weight:700;white-space:nowrap;font-family:monospace}
    .vp-sa .az-name{font-weight:600;font-size:14px}
    .vp-sa .az-note{font-size:12px;color:#7A8EA8;margin-top:3px}
    .vp-sa .tag{display:inline-block;padding:5px 14px;border-radius:20px;font-size:13px;font-weight:600;margin:4px;background:rgba(15,190,124,.12);border:1px solid rgba(15,190,124,.3);color:#0FBE7C}
    .vp-sa .vari{display:flex;align-items:flex-start;gap:12px;padding:12px 0;border-bottom:1px solid rgba(255,255,255,.06)}
    .vp-sa .vari:last-child{border-bottom:none}
    .vp-sa .vari-ico{width:36px;height:36px;border-radius:10px;background:rgba(232,96,10,.1);display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0}
    .vp-sa .vari-t{font-weight:600;font-size:14px}
    .vp-sa .vari-d{font-size:13px;color:#7A8EA8;margin-top:2px}
    .vp-sa .recom{display:flex;align-items:flex-start;gap:10px;padding:10px 0;border-bottom:1px solid rgba(255,255,255,.05);font-size:14px;color:rgba(255,255,255,.8)}
    .vp-sa .recom:last-child{border-bottom:none}
    .vp-sa .blocker{display:flex;align-items:flex-start;gap:12px;padding:12px;background:rgba(245,158,11,.07);border:1px solid rgba(245,158,11,.2);border-radius:10px;margin-bottom:10px}
    .vp-sa .blocker-ico{width:32px;height:32px;border-radius:8px;background:rgba(245,158,11,.15);display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0}
    .vp-sa .blocker-t{font-weight:600;font-size:14px;color:#F59E0B}
    .vp-sa .blocker-d{font-size:13px;color:rgba(255,255,255,.7);margin-top:3px}
    .vp-sa .desglose-table{width:100%;border-collapse:collapse;font-size:13px}
    .vp-sa .desglose-table th{text-align:left;color:#7A8EA8;font-size:11px;letter-spacing:.5px;text-transform:uppercase;padding:0 8px 10px;border-bottom:1px solid rgba(255,255,255,.08)}
    .vp-sa .desglose-table td{padding:8px;border-bottom:1px solid rgba(255,255,255,.04);vertical-align:top}
    .vp-sa .desglose-table td:last-child{text-align:right;white-space:nowrap;font-weight:700}
    .vp-sa .desglose-table tfoot td{font-weight:700;color:#E8600A;border-top:2px solid rgba(232,96,10,.3);border-bottom:none;padding-top:12px}
    .vp-sa .pts-pos{color:#0FBE7C}
    .vp-sa .pts-zero{color:#7A8EA8}
    .vp-sa .nota-nom{font-size:12px;color:#7A8EA8;margin-top:10px;line-height:1.6}
    .vp-sa .shortage-wrap{margin-top:14px;border-top:1px solid rgba(255,255,255,.06);padding-top:14px}
    .vp-sa .shortage-title{font-size:13px;font-weight:700;color:#7A8EA8;text-transform:uppercase;letter-spacing:.6px;margin-bottom:12px}
    .vp-sa .shortage-occ{background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.07);border-radius:12px;padding:14px;margin-bottom:10px}
    .vp-sa .shortage-occ-h{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:10px}
    .vp-sa .shortage-occ-name{font-weight:600;font-size:14px}
    .vp-sa .shortage-nat{font-size:12px;color:#7A8EA8;margin-left:auto}
    .vp-sa .shortage-states{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:6px;margin-bottom:10px}
    .vp-sa .shortage-state{display:flex;align-items:center;gap:6px;background:rgba(255,255,255,.04);border-radius:8px;padding:6px 10px;font-size:12px}
    .vp-sa .shortage-state.s .s-name{color:#0FBE7C;font-weight:700}
    .vp-sa .shortage-state.r .s-name{color:#60A5FA;font-weight:700}
    .vp-sa .shortage-state.m .s-name{color:#F59E0B;font-weight:700}
    .vp-sa .shortage-state .s-lbl{color:#7A8EA8;font-size:11px;flex:1;text-align:right}
    .vp-sa .shortage-demand{font-size:12px;color:rgba(255,255,255,.65);margin-bottom:8px}
    .vp-sa .shortage-link{font-size:12px}
    .vp-sa .shortage-link a{color:#E8600A;text-decoration:none}
    .vp-sa .shortage-link a:hover{text-decoration:underline}
    .vp-sa .footer{text-align:center;padding:24px 0 0;color:#7A8EA8;font-size:12px;border-top:1px solid rgba(255,255,255,.07);margin-top:24px}
    @media(max-width:600px){.vp-sa .scores{grid-template-columns:1fr 1fr}.vp-sa .meta{gap:10px}}
    </style>
    <div class="vp-sa">

      <!-- Disclaimer -->
      <div class="disc">⚠️ Este análisis fue generado por inteligencia artificial y tiene carácter 100% orientativo. Puede contener imprecisiones. Solo un agente migratorio registrado (MARA) puede confirmar tu elegibilidad real.</div>

      <!-- Datos del perfil -->
      <div class="meta">
        <span><strong><?php echo $nom . ' ' . $ape; ?></strong></span>
        <?php if ( $prof ) : ?><span>· <?php echo $prof; ?></span><?php endif; ?>
        <?php if ( $pais ) : ?><span>País: <strong><?php echo $pais; ?></strong></span><?php endif; ?>
        <?php if ( $edad ) : ?><span>Edad: <strong><?php echo $edad; ?></strong></span><?php endif; ?>
        <?php if ( $eng  ) : ?><span>Inglés: <strong><?php echo $eng; ?></strong></span><?php endif; ?>
      </div>

      <!-- Veredicto -->
      <div class="verdict <?php echo esc_attr( $viability === 'no-apto' ? 'no-apto' : $viability ); ?>">
        <div style="font-size:32px"><?php echo $viability === 'apto' ? '🟢' : '🟡'; ?></div>
        <div>
          <div style="font-weight:700;font-size:18px;margin-bottom:4px">
            <?php if ( $viability === 'apto' ) echo "🦘 {$nom}, buenas noticias!";
                  elseif ( $viability === 'parcial' ) echo "{$nom}, perfil con potencial";
                  else echo "💡 {$nom}, tu perfil tiene oportunidades de mejora"; ?>
          </div>
          <div style="font-size:13px;color:rgba(255,255,255,.65)">
            <?php if ( $viability === 'apto' ) echo 'Los indicadores sugieren que tu perfil podría avanzar en un proceso de General Skilled Migration a Australia.';
                  elseif ( $viability === 'parcial' ) echo 'Tu perfil cumple los mínimos pero hay variables que podrían trabajarse para ser más competitivo.';
                  else echo 'Se identificaron aspectos que podrían beneficiarse de mejoras antes de iniciar un proceso formal.'; ?>
          </div>
        </div>
      </div>

      <!-- Scores -->
      <div class="scores">
        <div class="sc">
          <div class="sc-lbl">Puntaje estimado</div>
          <div class="sc-val" style="color:#E8600A"><?php echo $pts; ?> pts</div>
          <div class="bar"><div class="bar-f" style="width:<?php echo min( ( $pts / 120 ) * 100, 100 ); ?>%;background:#E8600A"></div></div>
        </div>
        <div class="sc">
          <div class="sc-lbl">Viabilidad</div>
          <div class="sc-val" style="color:#0FBE7C"><?php echo $viaPct; ?>%</div>
          <div class="bar"><div class="bar-f" style="width:<?php echo $viaPct; ?>%;background:#0FBE7C"></div></div>
        </div>
        <div class="sc">
          <div class="sc-lbl">Competitividad</div>
          <div class="sc-val" style="color:#F59E0B"><?php echo $compPct; ?>%</div>
          <div class="bar"><div class="bar-f" style="width:<?php echo $compPct; ?>%;background:#F59E0B"></div></div>
        </div>
      </div>

      <!-- Desglose SkillSelect -->
      <?php if ( ! empty( $desglose ) ) : ?>
      <div class="sec">
        <div class="sec-h"><div class="sec-num">🔢</div>Desglose de puntos SkillSelect</div>
        <table class="desglose-table">
          <thead><tr><th>Factor</th><th>Detalle</th><th>Pts</th></tr></thead>
          <tbody>
            <?php
            $rows = [
              'Edad'               => $desglose['edad'] ?? null,
              'Inglés'             => $desglose['ingles'] ?? null,
              'Exp. offshore'      => $desglose['experienciaOffshore'] ?? null,
              'Exp. onshore (AU)'  => $desglose['experienciaOnshore'] ?? null,
              'Educación'          => $desglose['educacion'] ?? null,
              'Estudio en AU'      => $desglose['estudioAustralia'] ?? null,
              'Zona regional AU'   => $desglose['estudioRegional'] ?? null,
              'Ed. STEM AU'        => $desglose['educacionEspecializada'] ?? null,
              'Partner skills'     => $desglose['partnerSkills'] ?? null,
              'Professional Year'  => $desglose['professionalYear'] ?? null,
              'NAATI'              => $desglose['naati'] ?? null,
            ];
            foreach ( $rows as $label => $item ) :
              if ( empty( $item ) ) continue;
              $p   = absint( $item['puntos'] ?? 0 );
              $cls = $p > 0 ? 'pts-pos' : 'pts-zero';
            ?>
            <tr>
              <td><?php echo esc_html( $label ); ?></td>
              <td style="color:#7A8EA8;font-size:12px"><?php echo esc_html( $item['detalle'] ?? '' ); ?></td>
              <td class="<?php echo $cls; ?>"><?php echo $p; ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr>
              <td colspan="2">Subtotal (sin nominación)</td>
              <td><?php echo absint( $desglose['subtotal'] ?? $pts ); ?> pts</td>
            </tr>
          </tfoot>
        </table>
        <?php if ( ! empty( $desglose['notaNominacion'] ) ) : ?>
        <div class="nota-nom">📝 <?php echo esc_html( $desglose['notaNominacion'] ); ?></div>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <!-- Secciones del informe -->
      <?php if ( ! empty( $r['alcance'] ) ) : ?>
      <div class="sec"><div class="sec-h"><div class="sec-num">1</div>Naturaleza y alcance</div><div class="sec-body"><?php echo esc_html( $r['alcance'] ); ?></div></div>
      <?php endif; ?>
      <?php if ( ! empty( $r['academico'] ) ) : ?>
      <div class="sec"><div class="sec-h"><div class="sec-num">2</div>Análisis académico</div><div class="sec-body"><?php echo esc_html( $r['academico'] ); ?></div></div>
      <?php endif; ?>
      <?php if ( ! empty( $r['laboral'] ) ) : ?>
      <div class="sec"><div class="sec-h"><div class="sec-num">3</div>Análisis laboral</div><div class="sec-body"><?php echo esc_html( $r['laboral'] ); ?></div></div>
      <?php endif; ?>

      <!-- Marco ocupacional (ANZSCO) -->
      <?php if ( ! empty( $anzsco ) ) : ?>
      <div class="sec">
        <div class="sec-h"><div class="sec-num">4</div>Marco ocupacional (ANZSCO)</div>
        <?php foreach ( $anzsco as $a ) : ?>
        <div class="az-item">
          <span class="az-code"><?php echo esc_html( $a['code'] ?? '' ); ?></span>
          <div>
            <div class="az-name"><?php echo esc_html( $a['name'] ?? '' ); ?></div>
            <?php if ( ! empty( $a['note'] ) ) : ?><div class="az-note"><?php echo esc_html( $a['note'] ); ?></div><?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>

        <?php if ( ! empty( $shortage_map ) ) :
          $states_labels = [ 'NSW'=>'Nueva Gales del Sur', 'VIC'=>'Victoria', 'QLD'=>'Queensland', 'SA'=>'Australia del Sur', 'WA'=>'Australia Occidental', 'TAS'=>'Tasmania', 'NT'=>'Territorio del Norte', 'ACT'=>'Territorio de la Capital' ];
          $rating_icons  = [ 'S'=>'🟢', 'R'=>'🔵', 'M'=>'🟡', 'NS'=>'⚪' ];
          $rating_labels = [ 'S'=>'Escasez confirmada', 'R'=>'Escasez regional', 'M'=>'Escasez metropolitana', 'NS'=>'Sin escasez declarada' ];
          $demand_labels = [ 'very_high'=>'Muy alta — escasez en casi todo el país', 'high'=>'Alta — escasez en la mayoría de estados', 'moderate'=>'Moderada — escasez en algunos estados', 'some'=>'Localizada — escasez puntual', 'none'=>'Sin escasez detectada' ];
        ?>
        <div class="shortage-wrap">
          <div class="shortage-title">🗺️ Demanda laboral por estado (OSL 2025)</div>
          <?php foreach ( $shortage_map as $az_code => $sh ) :
            $az_name = '';
            foreach ( $anzsco as $a ) { if ( ( $a['code'] ?? '' ) === $az_code ) { $az_name = $a['name'] ?? ''; break; } }
            $nat_ico   = $rating_icons[ $sh['national'] ?? 'NS' ] ?? '⚪';
            $nat_label = $rating_labels[ $sh['national'] ?? 'NS' ] ?? $sh['national'];
            $dem_label = $demand_labels[ $sh['demandLevel'] ?? 'none' ] ?? '';
            $jsa_url   = 'https://www.jobsandskills.gov.au/jobs-and-skills-atlas/occupation?occupationFocus=' . substr( $az_code, 0, 4 );
          ?>
          <div class="shortage-occ">
            <div class="shortage-occ-h">
              <span class="az-code"><?php echo esc_html( $az_code ); ?></span>
              <span class="shortage-occ-name"><?php echo esc_html( $az_name ); ?></span>
              <span class="shortage-nat"><?php echo $nat_ico; ?> Nacional: <?php echo esc_html( $nat_label ); ?></span>
            </div>
            <div class="shortage-states">
              <?php foreach ( $sh['byState'] ?? [] as $state => $rating ) :
                $ico = $rating_icons[ $rating ] ?? '⚪';
                $lbl = $states_labels[ $state ] ?? $state;
              ?>
              <div class="shortage-state <?php echo strtolower( $rating ); ?>">
                <span class="s-ico"><?php echo $ico; ?></span>
                <span class="s-name"><?php echo esc_html( $state ); ?></span>
                <span class="s-lbl"><?php echo esc_html( $rating_labels[ $rating ] ?? $rating ); ?></span>
              </div>
              <?php endforeach; ?>
            </div>
            <?php if ( $dem_label ) : ?>
            <div class="shortage-demand">📊 Nivel de demanda: <strong><?php echo esc_html( $dem_label ); ?></strong></div>
            <?php endif; ?>
            <div class="shortage-link"><a href="<?php echo esc_url( $jsa_url ); ?>" target="_blank" rel="noopener">Ver datos en Jobs and Skills Atlas →</a></div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <!-- Visas potenciales -->
      <?php if ( ! empty( $visas ) ) : ?>
      <div class="sec">
        <div class="sec-h"><div class="sec-num">5</div>Visas potenciales</div>
        <div class="sec-body" style="margin-bottom:12px">Basado en la ocupación y el perfil actual, las siguientes subclases de visa podrían ser relevantes:</div>
        <?php foreach ( $visas as $v ) : ?>
        <span class="tag">Subclase <?php echo esc_html( $v ); ?></span>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <!-- Variables de competitividad -->
      <?php if ( ! empty( $variables ) ) : ?>
      <div class="sec">
        <div class="sec-h"><div class="sec-num">6</div>Variables de competitividad</div>
        <?php foreach ( $variables as $v ) :
          $ico = $icons_map[ $v['icon'] ?? '' ] ?? ( $v['icon'] ?? '•' );
        ?>
        <div class="vari">
          <div class="vari-ico"><?php echo $ico; ?></div>
          <div>
            <div class="vari-t"><?php echo esc_html( $v['title'] ?? '' ); ?></div>
            <div class="vari-d"><?php echo esc_html( $v['desc'] ?? '' ); ?></div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <!-- Recomendaciones -->
      <?php if ( ! empty( $recom ) ) : ?>
      <div class="sec">
        <div class="sec-h"><div class="sec-num">7</div>Recomendaciones</div>
        <?php foreach ( $recom as $rec ) : ?>
        <div class="recom">
          <span style="font-size:18px"><?php $ri = $rec['icon'] ?? 'target'; echo $icons_map[$ri] ?? '🎯'; ?></span>
          <div><?php echo esc_html( $rec['texto'] ?? '' ); ?></div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <!-- Bloqueantes (no-apto) -->
      <?php if ( ! empty( $bloq ) ) : ?>
      <div class="sec">
        <div class="sec-h"><div class="sec-num">⚡</div>Aspectos a fortalecer</div>
        <?php foreach ( $bloq as $b ) : ?>
        <div class="blocker">
          <div class="blocker-ico">⚡</div>
          <div>
            <div class="blocker-t"><?php echo esc_html( $b['titulo'] ?? $b['title'] ?? '' ); ?></div>
            <div class="blocker-d"><?php echo esc_html( $b['desc'] ?? '' ); ?></div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <!-- Footer -->
      <div class="footer">
        Informe preliminar orientativo &mdash; Viva Australia Internacional &middot; Frank Cross, Senior Migration Agent &middot; MARA 0101111
      </div>
    </div>
    <?php
}

// ═══════════════════════════════════════════════════════════════
// REST API — REGISTRO
// ═══════════════════════════════════════════════════════════════
function viva_preevm_register_rest_routes() {
    $ns  = 'viva/v1';
    $pub = [ 'permission_callback' => '__return_true' ];
    register_rest_route( $ns, '/ghl-lookup',  array_merge( [ 'methods' => 'POST', 'callback' => 'viva_rest_ghl_lookup'  ], $pub ) );
    register_rest_route( $ns, '/ghl-upsert',  array_merge( [ 'methods' => 'POST', 'callback' => 'viva_rest_ghl_upsert'  ], $pub ) );
    register_rest_route( $ns, '/ghl-tag',     array_merge( [ 'methods' => 'POST', 'callback' => 'viva_rest_ghl_tag'     ], $pub ) );
    register_rest_route( $ns, '/ghl-note',    array_merge( [ 'methods' => 'POST', 'callback' => 'viva_rest_ghl_note'    ], $pub ) );
    register_rest_route( $ns, '/analyze',     array_merge( [ 'methods' => 'POST', 'callback' => 'viva_rest_analyze'     ], $pub ) );
    register_rest_route( $ns, '/save-result', array_merge( [ 'methods' => 'POST', 'callback' => 'viva_rest_save_result' ], $pub ) );
    register_rest_route( $ns, '/save-draft',  array_merge( [ 'methods' => 'POST', 'callback' => 'viva_rest_save_draft'  ], $pub ) );
    register_rest_route( $ns, '/get-draft',   array_merge( [ 'methods' => 'GET',  'callback' => 'viva_rest_get_draft'   ], $pub ) );
}

// ── GHL Lookup ────────────────────────────────────────────────
function viva_rest_ghl_lookup( WP_REST_Request $req ) {
    $email = sanitize_email( $req->get_param( 'email' ) );
    if ( ! is_email( $email ) ) return new WP_Error( 'invalid_email', 'Email inválido', [ 'status' => 400 ] );
    $resp = viva_ghl_request( 'POST', '/contacts/search', [
        'locationId' => GHL_LOCATION_ID,
        'filters'    => [ [ 'field' => 'email', 'operator' => 'eq', 'value' => $email ] ],
    ] );
    if ( is_wp_error( $resp ) ) return [ 'found' => false ];
    $body     = json_decode( wp_remote_retrieve_body( $resp ), true );
    $contacts = $body['contacts'] ?? [];
    if ( empty( $contacts ) ) return [ 'found' => false ];
    $c = $contacts[0];
    return [ 'found' => true, 'contactId' => $c['id'] ?? '', 'firstName' => $c['firstName'] ?? '', 'lastName' => $c['lastName'] ?? '', 'phone' => $c['phone'] ?? '', 'tags' => $c['tags'] ?? [] ];
}

// ── GHL Upsert ────────────────────────────────────────────────
function viva_rest_ghl_upsert( WP_REST_Request $req ) {
    $data = [
        'locationId' => GHL_LOCATION_ID,
        'email'      => sanitize_email( $req->get_param( 'email' ) ),
        'firstName'  => sanitize_text_field( $req->get_param( 'firstName' ) ?? '' ),
        'lastName'   => sanitize_text_field( $req->get_param( 'lastName' )  ?? '' ),
        'phone'      => sanitize_text_field( $req->get_param( 'phone' )     ?? '' ),
        'country'    => sanitize_text_field( $req->get_param( 'country' )   ?? '' ),
        'source'     => 'Pre-EVM Test',
    ];
    $custom = $req->get_param( 'customFields' );
    if ( ! empty( $custom ) && is_array( $custom ) ) {
        $data['customFields'] = array_map( function( $cf ) {
            return [ 'key' => sanitize_text_field( $cf['key'] ?? '' ), 'field_value' => sanitize_text_field( $cf['field_value'] ?? '' ) ];
        }, $custom );
    }
    $resp = viva_ghl_request( 'POST', '/contacts/upsert', $data );
    if ( is_wp_error( $resp ) ) return new WP_Error( 'ghl_error', 'Error GHL', [ 'status' => 500 ] );
    $body = json_decode( wp_remote_retrieve_body( $resp ), true );
    return [ 'contactId' => $body['contact']['id'] ?? $body['id'] ?? '' ];
}

// ── GHL Tag ───────────────────────────────────────────────────
function viva_rest_ghl_tag( WP_REST_Request $req ) {
    $cid  = sanitize_text_field( $req->get_param( 'contactId' ) );
    $tags = array_map( 'sanitize_text_field', (array) ( $req->get_param( 'tags' ) ?? [] ) );
    if ( empty( $cid ) || empty( $tags ) ) return new WP_Error( 'params', 'Parámetros requeridos', [ 'status' => 400 ] );
    $resp = viva_ghl_request( 'POST', "/contacts/{$cid}/tags", [ 'tags' => $tags ] );
    $code = (int) wp_remote_retrieve_response_code( $resp );
    return [ 'success' => $code >= 200 && $code < 300 ];
}

// ── GHL Note ──────────────────────────────────────────────────
function viva_rest_ghl_note( WP_REST_Request $req ) {
    $cid       = sanitize_text_field( $req->get_param( 'contactId' ) );
    $body_text = sanitize_textarea_field( $req->get_param( 'body' ) );
    if ( empty( $cid ) || empty( $body_text ) ) return new WP_Error( 'params', 'Parámetros requeridos', [ 'status' => 400 ] );
    $resp = viva_ghl_request( 'POST', "/contacts/{$cid}/notes", [ 'body' => $body_text, 'userId' => null ] );
    $code = (int) wp_remote_retrieve_response_code( $resp );
    return [ 'success' => $code >= 200 && $code < 300 ];
}

// ── Analyze — dispatcher multi-proveedor ──────────────────────
function viva_rest_analyze( WP_REST_Request $req ) {
    $ip = sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0' );
    if ( ! viva_check_rate_limit( $ip ) ) {
        return new WP_Error( 'rate_limit', 'Límite alcanzado. Intenta en una hora.', [ 'status' => 429 ] );
    }

    $provider  = get_option( 'viva_ai_provider', 'anthropic' );
    $user_msg  = viva_build_user_message( $req );
    $cv_base64 = $req->get_param( 'cvBase64' ) ?? '';
    $cv_mime   = sanitize_text_field( $req->get_param( 'cvMime' ) ?? 'application/pdf' );

    // Despachar al proveedor activo
    switch ( $provider ) {
        case 'openai':
            $text = viva_call_openai( $user_msg, $cv_base64, $cv_mime );
            break;
        case 'gemini':
            $text = viva_call_gemini( $user_msg, $cv_base64, $cv_mime );
            break;
        default: // anthropic
            $text = viva_call_anthropic( $user_msg, $cv_base64, $cv_mime );
            break;
    }

    if ( is_wp_error( $text ) ) return $text;

    // Normalizar respuesta: eliminar bloque ```json ... ```
    $raw_text = $text;
    $text     = trim( preg_replace( [ '/^```(?:json)?\s*/i', '/\s*```$/' ], '', trim( $text ) ) );
    $result   = json_decode( $text, true );

    // Log de debug en WordPress (visible en debug.log si WP_DEBUG_LOG está activo)
    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        error_log( '[VIVA PRE-EVM] Provider: ' . $provider );
        error_log( '[VIVA PRE-EVM] Raw IA response (primeros 800 chars): ' . substr( $raw_text, 0, 800 ) );
        if ( $result ) {
            error_log( '[VIVA PRE-EVM] Parse OK — viability: ' . ( $result['viability'] ?? 'MISSING' ) . ' pts: ' . ( $result['pts'] ?? 'MISSING' ) );
        }
    }

    if ( ! $result ) {
        return new WP_Error(
            'parse_error',
            'Error parseando JSON de IA. Provider: ' . $provider . '. Respuesta (200 chars): ' . esc_html( substr( $raw_text, 0, 200 ) ),
            [ 'status' => 500 ]
        );
    }

    // Decodificar secuencias \uXXXX que la IA inserta como texto literal dentro de los strings.
    $result = viva_decode_unicode_in_array( $result );

    // Enriquecer con datos de escasez OSL 2025 por cada código ANZSCO devuelto
    $anzsco_list = $result['anzsco'] ?? [];
    if ( is_array( $anzsco_list ) && ! empty( $anzsco_list ) ) {
        $shortage_map = [];
        foreach ( $anzsco_list as $az ) {
            $code = $az['code'] ?? '';
            if ( ! $code ) continue;
            $osl = viva_get_shortage_data( $code );
            if ( $osl ) {
                $shortage_map[ $code ] = viva_build_shortage_summary( $osl );
            }
        }
        if ( ! empty( $shortage_map ) ) {
            $result['shortageMap'] = $shortage_map;
        }
    }

    $result['nom']      = sanitize_text_field( $req->get_param( 'nombre' )    ?? '' );
    $result['ape']      = sanitize_text_field( $req->get_param( 'apellido' )  ?? '' );
    $result['email']    = sanitize_email(      $req->get_param( 'email' )     ?? '' );
    $result['pais']     = sanitize_text_field( $req->get_param( 'pais' )      ?? '' );
    $result['edad']     = sanitize_text_field( $req->get_param( 'edad' )      ?? '' );
    $result['eng']      = sanitize_text_field( $req->get_param( 'ingles' )    ?? '' );
    $result['prof']     = sanitize_text_field( $req->get_param( 'profesion' ) ?? '' );
    $result['_provider'] = $provider; // para debugging
    return $result;
}

// ── Proveedor: Anthropic Claude ───────────────────────────────
function viva_call_anthropic( string $user_msg, string $cv_base64, string $cv_mime ) {
    $ak    = get_option( 'viva_anthropic_key', '' );
    $model = get_option( 'viva_anthropic_model', 'claude-sonnet-4-20250514' );
    if ( empty( $ak ) ) return new WP_Error( 'no_key', 'API Key de Anthropic no configurada.', [ 'status' => 500 ] );

    // Anthropic soporta PDF como documento nativo
    if ( ! empty( $cv_base64 ) && $cv_mime === 'application/pdf' ) {
        $user_content = [
            [ 'type' => 'document', 'source' => [ 'type' => 'base64', 'media_type' => 'application/pdf', 'data' => $cv_base64 ] ],
            [ 'type' => 'text', 'text' => $user_msg ],
        ];
    } elseif ( ! empty( $cv_base64 ) ) {
        $cv_text = base64_decode( $cv_base64 );
        $user_content = $user_msg . "\n\nTEXTO DEL CV:\n" . mb_substr( $cv_text, 0, 4000 );
    } else {
        $user_content = $user_msg;
    }

    $resp = wp_remote_post( 'https://api.anthropic.com/v1/messages', [
        'timeout' => 120,
        'headers' => [
            'x-api-key'         => $ak,
            'anthropic-version' => '2023-06-01',
            'Content-Type'      => 'application/json',
        ],
        'body' => wp_json_encode( [
            'model'      => $model,
            'max_tokens' => 3000,
            'system'     => viva_build_system_prompt(),
            'messages'   => [ [ 'role' => 'user', 'content' => $user_content ] ],
        ] ),
    ] );

    if ( is_wp_error( $resp ) ) return new WP_Error( 'anthropic_error', $resp->get_error_message(), [ 'status' => 500 ] );
    $code = (int) wp_remote_retrieve_response_code( $resp );
    $body = json_decode( wp_remote_retrieve_body( $resp ), true );
    if ( $code !== 200 ) return new WP_Error( 'anthropic_error', $body['error']['message'] ?? 'Error Anthropic', [ 'status' => 500 ] );

    return $body['content'][0]['text'] ?? '';
}

// ── Proveedor: OpenAI ─────────────────────────────────────────
function viva_call_openai( string $user_msg, string $cv_base64, string $cv_mime ) {
    $ak    = get_option( 'viva_openai_key', '' );
    $model = get_option( 'viva_openai_model', 'gpt-4o' );
    if ( empty( $ak ) ) return new WP_Error( 'no_key', 'API Key de OpenAI no configurada.', [ 'status' => 500 ] );

    // OpenAI no soporta PDF nativo: extraemos texto del base64
    if ( ! empty( $cv_base64 ) ) {
        $cv_text = base64_decode( $cv_base64 );
        $user_msg .= "\n\nTEXTO DEL CV:\n" . mb_substr( $cv_text, 0, 6000 );
    }

    $resp = wp_remote_post( 'https://api.openai.com/v1/chat/completions', [
        'timeout' => 120,
        'headers' => [
            'Authorization' => 'Bearer ' . $ak,
            'Content-Type'  => 'application/json',
        ],
        'body' => wp_json_encode( [
            'model'       => $model,
            'max_tokens'  => 3000,
            'temperature' => 0.3,
            'messages'    => [
                [ 'role' => 'system', 'content' => viva_build_system_prompt() ],
                [ 'role' => 'user',   'content' => $user_msg ],
            ],
        ] ),
    ] );

    if ( is_wp_error( $resp ) ) return new WP_Error( 'openai_error', $resp->get_error_message(), [ 'status' => 500 ] );
    $code = (int) wp_remote_retrieve_response_code( $resp );
    $body = json_decode( wp_remote_retrieve_body( $resp ), true );
    if ( $code !== 200 ) return new WP_Error( 'openai_error', $body['error']['message'] ?? 'Error OpenAI', [ 'status' => 500 ] );

    return $body['choices'][0]['message']['content'] ?? '';
}

// ── Proveedor: Google Gemini ──────────────────────────────────
function viva_call_gemini( string $user_msg, string $cv_base64, string $cv_mime ) {
    $ak    = get_option( 'viva_gemini_key', '' );
    $model = get_option( 'viva_gemini_model', 'gemini-2.0-flash' );
    if ( empty( $ak ) ) return new WP_Error( 'no_key', 'API Key de Gemini no configurada.', [ 'status' => 500 ] );

    $system_prompt = viva_build_system_prompt();

    // Gemini soporta PDF vía inline_data
    $parts = [];
    if ( ! empty( $cv_base64 ) ) {
        $parts[] = [
            'inline_data' => [
                'mime_type' => ( $cv_mime === 'application/pdf' ) ? 'application/pdf' : 'text/plain',
                'data'      => $cv_base64,
            ],
        ];
    }
    $parts[] = [ 'text' => $user_msg ];

    $url  = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode( $model ) . ':generateContent?key=' . rawurlencode( $ak );
    $resp = wp_remote_post( $url, [
        'timeout' => 120,
        'headers' => [ 'Content-Type' => 'application/json' ],
        'body'    => wp_json_encode( [
            'system_instruction' => [ 'parts' => [ [ 'text' => $system_prompt ] ] ],
            'contents'           => [ [ 'role' => 'user', 'parts' => $parts ] ],
            'generationConfig'   => [ 'maxOutputTokens' => 3000, 'temperature' => 0.3 ],
        ] ),
    ] );

    if ( is_wp_error( $resp ) ) return new WP_Error( 'gemini_error', $resp->get_error_message(), [ 'status' => 500 ] );
    $code = (int) wp_remote_retrieve_response_code( $resp );
    $body = json_decode( wp_remote_retrieve_body( $resp ), true );
    if ( $code !== 200 ) {
        $msg = $body['error']['message'] ?? 'Error Gemini';
        return new WP_Error( 'gemini_error', $msg, [ 'status' => 500 ] );
    }

    return $body['candidates'][0]['content']['parts'][0]['text'] ?? '';
}

// ── Save Result ───────────────────────────────────────────────
function viva_rest_save_result( WP_REST_Request $req ) {
    $data     = $req->get_json_params() ?? [];
    $nombre   = sanitize_text_field( $data['nombre']   ?? '' );
    $apellido = sanitize_text_field( $data['apellido'] ?? '' );
    $result   = $data['result'] ?? [];
    $post_id  = wp_insert_post( [
        'post_type'   => 'preevm_result',
        'post_title'  => $nombre . ' ' . $apellido . ' — ' . date( 'Y-m-d H:i' ),
        'post_status' => 'publish',
    ] );
    if ( is_wp_error( $post_id ) ) return new WP_Error( 'save_error', 'Error guardando resultado', [ 'status' => 500 ] );
    $meta_fields = [
        '_preevm_nombre'             => $nombre,
        '_preevm_apellido'           => $apellido,
        '_preevm_email'              => sanitize_email( $data['email']       ?? '' ),
        '_preevm_whatsapp'           => sanitize_text_field( $data['whatsapp']    ?? '' ),
        '_preevm_pais'               => sanitize_text_field( $data['pais']        ?? '' ),
        '_preevm_profesion'          => sanitize_text_field( $data['profesion']   ?? '' ),
        '_preevm_edad'               => sanitize_text_field( $data['edad']        ?? '' ),
        '_preevm_ingles'             => sanitize_text_field( $data['ingles']      ?? '' ),
        '_preevm_experiencia'        => sanitize_text_field( $data['experiencia'] ?? '' ),
        '_preevm_viability'          => sanitize_text_field( $result['viability'] ?? '' ),
        '_preevm_puntaje'            => absint( $result['pts']     ?? 0 ),
        '_preevm_viabilidad_pct'     => absint( $result['viaPct']  ?? 0 ),
        '_preevm_competitividad_pct' => absint( $result['compPct'] ?? 0 ),
        '_preevm_resultado_json'     => wp_json_encode( $result ),
        '_preevm_contacto_ghl'       => sanitize_text_field( $data['contactId']  ?? '' ),
        '_preevm_timestamp'          => current_time( 'mysql' ),
    ];
    foreach ( $meta_fields as $k => $v ) update_post_meta( $post_id, $k, $v );
    return [ 'resultUrl' => get_permalink( $post_id ), 'resultId' => $post_id ];
}

// ── Save Draft ────────────────────────────────────────────────
function viva_rest_save_draft( WP_REST_Request $req ) {
    $data    = $req->get_json_params() ?? [];
    $token   = wp_generate_password( 32, false );
    $post_id = wp_insert_post( [
        'post_type'   => 'preevm_draft',
        'post_title'  => 'Draft ' . sanitize_email( $data['email'] ?? '' ),
        'post_status' => 'publish',
    ] );
    if ( is_wp_error( $post_id ) ) return new WP_Error( 'save_error', 'Error guardando borrador', [ 'status' => 500 ] );
    update_post_meta( $post_id, '_preevm_draft_token',  $token );
    update_post_meta( $post_id, '_preevm_draft_data',   wp_json_encode( $data ) );
    update_post_meta( $post_id, '_preevm_draft_expiry', time() + 30 * DAY_IN_SECONDS );
    $page_url     = viva_get_shortcode_page_url();
    $continue_url = add_query_arg( 'continuar', $token, $page_url );
    return [ 'continueUrl' => $continue_url, 'token' => $token ];
}

// ── Get Draft ─────────────────────────────────────────────────
function viva_rest_get_draft( WP_REST_Request $req ) {
    $token = sanitize_text_field( $req->get_param( 'token' ) );
    if ( empty( $token ) ) return new WP_Error( 'missing', 'Token requerido', [ 'status' => 400 ] );
    $posts = get_posts( [ 'post_type' => 'preevm_draft', 'meta_key' => '_preevm_draft_token', 'meta_value' => $token, 'posts_per_page' => 1 ] );
    if ( empty( $posts ) ) return new WP_Error( 'not_found', 'Link no válido o expirado', [ 'status' => 404 ] );
    $post   = $posts[0];
    $expiry = (int) get_post_meta( $post->ID, '_preevm_draft_expiry', true );
    if ( $expiry < time() ) {
        wp_delete_post( $post->ID, true );
        return new WP_Error( 'expired', 'El link ha expirado', [ 'status' => 410 ] );
    }
    return [ 'data' => json_decode( get_post_meta( $post->ID, '_preevm_draft_data', true ), true ), 'postId' => $post->ID ];
}

// ═══════════════════════════════════════════════════════════════
// HELPERS
// ═══════════════════════════════════════════════════════════════
function viva_ghl_request( $method, $path, $body = null ) {
    $ghl_key = get_option( 'viva_ghl_key', GHL_API_KEY );
    $args    = [
        'timeout' => 30,
        'headers' => [ 'Authorization' => 'Bearer ' . $ghl_key, 'Version' => '2021-07-28', 'Content-Type' => 'application/json' ],
    ];
    if ( $body !== null ) $args['body'] = wp_json_encode( $body );
    return $method === 'POST' ? wp_remote_post( GHL_BASE_URL . $path, $args ) : wp_remote_get( GHL_BASE_URL . $path, $args );
}

/**
 * Obtiene datos de escasez del OSL 2025 para un código ANZSCO dado.
 * Usa un transient para no leer el JSON en cada request.
 */
function viva_get_shortage_data( string $anzsco_code ) {
    $osl_file = plugin_dir_path( __FILE__ ) . 'osl_shortage_2025.json';
    if ( ! file_exists( $osl_file ) ) return null;

    $cache_key = 'viva_osl_index';
    $index     = get_transient( $cache_key );
    if ( false === $index ) {
        $raw   = file_get_contents( $osl_file );
        $rows  = json_decode( $raw, true );
        $index = [];
        if ( is_array( $rows ) ) {
            foreach ( $rows as $row ) {
                if ( ! empty( $row['code'] ) ) $index[ $row['code'] ] = $row;
            }
        }
        set_transient( $cache_key, $index, 12 * HOUR_IN_SECONDS );
    }
    return $index[ $anzsco_code ] ?? null;
}

/**
 * Calcula demandLevel e información de shortage para incluir en el resultado.
 */
function viva_build_shortage_summary( array $osl ) {
    $states = [ 'nsw', 'vic', 'qld', 'sa', 'wa', 'tas', 'nt', 'act' ];
    $shortage_states = [];
    foreach ( $states as $s ) {
        if ( strtoupper( $osl[ $s ] ?? '' ) === 'S' ) $shortage_states[] = strtoupper( $s );
    }
    $count    = count( $shortage_states );
    $national = strtoupper( $osl['national'] ?? 'NS' );
    if     ( $count >= 7 )              $level = 'very_high';
    elseif ( $count >= 4 )              $level = 'high';
    elseif ( $count >= 2 )              $level = 'moderate';
    elseif ( $count >= 1 )              $level = 'some';
    else                                $level = 'none';
    return [
        'national'       => $national,
        'byState'        => [
            'NSW' => strtoupper( $osl['nsw'] ?? 'NS' ),
            'VIC' => strtoupper( $osl['vic'] ?? 'NS' ),
            'QLD' => strtoupper( $osl['qld'] ?? 'NS' ),
            'SA'  => strtoupper( $osl['sa']  ?? 'NS' ),
            'WA'  => strtoupper( $osl['wa']  ?? 'NS' ),
            'TAS' => strtoupper( $osl['tas'] ?? 'NS' ),
            'NT'  => strtoupper( $osl['nt']  ?? 'NS' ),
            'ACT' => strtoupper( $osl['act'] ?? 'NS' ),
        ],
        'shortageStates' => $shortage_states,
        'shortageCount'  => $count,
        'demandLevel'    => $level,
    ];
}

/**
 * Decodifica recursivamente secuencias \uXXXX que la IA inserta como texto literal
 * en los valores string del array resultado. Cubre:
 *   - BMP:  \u00e9  → é
 *   - Surrogate pairs (emoji): \uD83C\uDFAF → 🎯
 */
function viva_decode_unicode_in_array( $data ) {
    if ( is_array( $data ) ) {
        return array_map( 'viva_decode_unicode_in_array', $data );
    }
    if ( ! is_string( $data ) ) {
        return $data;
    }
    // Envolver en comillas y decodificar como si fuera un string JSON
    // Esto convierte \u00e9 → é y pares surrogados → emoji correctamente
    $encoded = json_encode( $data ); // convierte é→\u00e9 si ya es UTF-8, lo dejamos como string JSON
    // Reemplazar secuencias \uXXXX literales (doble-escapadas: \\u00e9 en el JSON original)
    // que quedaron como \u00e9 en la cadena PHP tras json_decode
    $decoded = preg_replace_callback(
        '/\\\\u([0-9a-fA-F]{4})/',
        function ( $m ) {
            $cp = hexdec( $m[1] );
            // Convertir code point a UTF-8
            if ( $cp < 0x80 )   return chr( $cp );
            if ( $cp < 0x800 )  return chr( 0xC0 | ( $cp >> 6 ) ) . chr( 0x80 | ( $cp & 0x3F ) );
            if ( $cp < 0x10000 ) return chr( 0xE0 | ( $cp >> 12 ) ) . chr( 0x80 | ( ( $cp >> 6 ) & 0x3F ) ) . chr( 0x80 | ( $cp & 0x3F ) );
            return $m[0]; // fuera de BMP — dejar como está
        },
        $data
    );
    return $decoded;
}

function viva_check_rate_limit( $ip ) {
    // Los administradores de WP no tienen límite (facilita testing)
    if ( is_user_logged_in() && current_user_can( 'manage_options' ) ) return true;
    $key   = 'viva_rl_' . md5( $ip );
    $count = (int) get_transient( $key );
    if ( $count >= 5 ) return false;
    set_transient( $key, $count + 1, HOUR_IN_SECONDS );
    return true;
}

function viva_get_shortcode_page_url() {
    global $wpdb;
    $pid = $wpdb->get_var( "SELECT ID FROM {$wpdb->posts} WHERE post_status='publish' AND post_type='page' AND post_content LIKE '%[viva_pre_evm%' LIMIT 1" );
    return $pid ? get_permalink( (int) $pid ) : get_site_url( null, '/pre-evm/' );
}

function viva_build_system_prompt() {
    return implode( "\n", [
        'Eres un analista técnico de perfiles migratorios para Australia (General Skilled Migration).',
        'Tu trabajo es analizar perfiles con criterio técnico PRECISO, pero tu tono es constructivo y orientativo, NUNCA categórico.',
        '',
        'REGLA DE ORO DE TONO:',
        '- NUNCA uses frases categóricas como "usted califica", "usted no califica", "es elegible", "no es elegible".',
        '- USA siempre lenguaje condicional: "los datos sugieren que", "el perfil podría ser elegible", "al parecer", "según los indicadores".',
        '- Para perfiles positivos: "Los indicadores sugieren que este perfil podría avanzar en un proceso de GSM."',
        '- Para perfiles con bloqueantes: "Se identificaron aspectos del perfil que podrían beneficiarse de mejoras antes de iniciar un proceso."',
        '- Siempre recuerda que eres una IA orientativa y que solo un agente MARA puede confirmar elegibilidad.',
        '',
        'SISTEMA DE PUNTOS SKILLSELECT 2025-26 (referencia oficial):',
        '',
        'EDAD:',
        '- 18-24 años = 25 pts | 25-32 años = 30 pts (máximo) | 33-39 años = 25 pts | 40-44 años = 15 pts | 45+ años = 0 pts',
        '',
        'INGLÉS:',
        '- Competent (IELTS 6 / PTE 50) = 0 pts (mínimo) | Proficient (IELTS 7 / PTE 65) = 10 pts | Superior (IELTS 8 / PTE 79) = 20 pts',
        '- Si declara "Avanzado" sin certificación: asumir Competent potencial (0 pts) pero recomendar certificar.',
        '- Si declara "Intermedio": advertir que necesita Competent English certificado.',
        '- Si declara "Básico" o "Ninguno": marcar como aspecto crítico a trabajar.',
        '',
        'EXPERIENCIA LABORAL CALIFICADA (últimos 10 años, en ocupación nominada):',
        '- Offshore (fuera de AU): <3 = 0 pts | 3-4 = 5 pts | 5-7 = 10 pts | 8+ = 15 pts',
        '- Onshore (en AU): <1 = 0 pts | 1-2 = 5 pts | 3-4 = 10 pts | 5-7 = 15 pts | 8+ = 20 pts',
        '- Máximo combinado offshore + onshore: 20 pts.',
        '',
        'EDUCACIÓN:',
        '- Doctorado = 20 pts | Bachelor degree = 15 pts | Diploma o trade qualification AU = 10 pts',
        '',
        'ESTUDIO EN AUSTRALIA (mín 2 años) = 5 pts | ZONA REGIONAL de AU = 5 pts',
        '',
        'EDUCACIÓN ESPECIALIZADA: Maestría de investigación o Doctorado en AU en STEM = 10 pts',
        '',
        'PARTNER SKILLS:',
        '- Soltero o partner es ciudadano/RP australiano = 10 pts',
        '- Partner tiene Competent English + Skills Assessment = 10 pts',
        '- Partner tiene Competent English sin Skills Assessment = 5 pts',
        '- Partner no cumple ninguno = 0 pts',
        '',
        'PROFESSIONAL YEAR en Australia = 5 pts | NAATI = 5 pts',
        '',
        'NOMINACIÓN: NO incluir en cálculo base. Indicar: "Con nominación estatal (190) +5 pts; con regional (491) +15 pts".',
        '',
        'PUNTAJE MÍNIMO EOI: 65 pts | RANGO COMPETITIVO: 80-95+ pts',
        '',
        'ADVERTENCIAS (usar tono constructivo):',
        '- Edad 45+: "El sistema de puntos no asigna puntos por edad a partir de los 45 años."',
        '- Sin inglés: "El inglés certificado a nivel Competent (IELTS 6.0) es un requisito fundamental."',
        '- Sin título: "Un título equivalente a Bachelor degree australiano es generalmente necesario."',
        '- <65 pts: "El puntaje estimado está por debajo del mínimo, pero existen formas de mejorar el score."',
        '',
        'VEREDICTO (interno):',
        '- no-apto: uno o más factores críticos presentes.',
        '- parcial: pts 65-79, o inglés sin certificar, o experiencia 3-4 años.',
        '- apto: pts 80+, inglés Proficient+, experiencia 5+, ocupación probablemente en lista.',
        '',
        'ANÁLISIS DE CV:',
        '- Si hay CV adjunto: extraer títulos exactos, universidades, fechas, empresas, cargos, responsabilidades.',
        '- Usar el CV para inferir códigos ANZSCO específicos basados en actividades descritas.',
        '- NUNCA generar un análisis genérico. Cada sección debe referenciar datos específicos del CV.',
        '',
        'ESTRUCTURA JSON OBLIGATORIA — debes devolver EXACTAMENTE estos campos (no inventes nombres alternativos):',
        '',
        '{',
        '  "viability": "apto|parcial|no-apto",',
        '  "pts": <número entero 0-120, suma del desglose sin nominación>,',
        '  "viaPct": <porcentaje 0-100 de viabilidad general>,',
        '  "compPct": <porcentaje 0-100 de competitividad en pool>,',
        '  "alcance": "<párrafo: naturaleza del análisis, qué se evaluó y limitaciones>",',
        '  "academico": "<párrafo: análisis del título, homologación, entidad evaluadora, puntos de educación>",',
        '  "laboral": "<párrafo: análisis de experiencia calificada, ocupación ANZSCO, puntos de experiencia>",',
        '  "anzsco": [{"code": "233211", "name": "Civil Engineer", "note": "<por qué este código>"}],',
        '  "visas": ["189", "190", "491"],',
        '  "variables": [{"icon": "briefcase|cake|speech|clipboard|grad|chart|book|key", "title": "<título>", "desc": "<descripción>"}],',
        '  "recomendaciones": [{"icon": "target|star|check|rocket|key|book|chart|time", "texto": "<recomendación concreta accionable>"}],',
        '  "bloqueantes": [{"icon": "X", "titulo": "<factor crítico>", "desc": "<qué implica y cómo trabajarlo>"}],',
        'IMPORTANTE: El campo "icon" debe ser SIEMPRE una de las claves de texto indicadas (target, star, check, briefcase, etc.). NUNCA uses emojis ni secuencias \\uXXXX en los valores de "icon".',
        'IMPORTANTE: Todos los textos en los strings del JSON deben estar en UTF-8 limpio. NUNCA uses secuencias de escape \\u00e9 — escribe directamente "é", "á", "ó", "ñ", etc.',
        '  "proximoPaso": "<texto del CTA para el usuario no-apto>",',
        '  "desglosePuntos": {',
        '    "edad": {"puntos": <n>, "detalle": "<rango de edad>"},',
        '    "ingles": {"puntos": <n>, "detalle": "<nivel y certificación>"},',
        '    "experienciaOffshore": {"puntos": <n>, "detalle": "<años fuera de AU>"},',
        '    "experienciaOnshore": {"puntos": <n>, "detalle": "<años en AU>"},',
        '    "educacion": {"puntos": <n>, "detalle": "<tipo de título>"},',
        '    "estudioAustralia": {"puntos": <n>, "detalle": "<si estudió en AU>"},',
        '    "estudioRegional": {"puntos": <n>, "detalle": "<si fue zona regional AU>"},',
        '    "educacionEspecializada": {"puntos": <n>, "detalle": "<maestría/doctorado STEM en AU>"},',
        '    "partnerSkills": {"puntos": <n>, "detalle": "<situación de pareja>"},',
        '    "professionalYear": {"puntos": <n>, "detalle": "<si hizo PY en AU>"},',
        '    "naati": {"puntos": <n>, "detalle": "<si tiene NAATI>"},',
        '    "subtotal": <suma de todos los puntos anteriores>,',
        '    "notaNominacion": "Con nominación estatal (190): +5 pts | Con regional (491): +15 pts."',
        '  }',
        '}',
        '',
        'REGLAS DE VIABILITY:',
        '- "apto": pts >= 80 Y inglés tiene certificación Proficient+ (IELTS 7+ o PTE 65+) Y experiencia >= 5 años.',
        '- "parcial": pts 65-79 O inglés sin certificar O experiencia 3-4 años O algún factor mejorable. NO uses no-apto si el perfil tiene oportunidades reales.',
        '- "no-apto": SOLO si hay factores absolutamente bloqueantes — edad 45+, inglés básico/ninguno sin posibilidad, profesión fuera de las listas australianas, o pts < 50 incluso con nominación.',
        '',
        'IMPORTANTE: Un ingeniero civil (233211) con edad 25-32, título universitario, y 5+ años de experiencia NUNCA es no-apto. Calcula correctamente.',
        '',
        'Responde Única y exclusivamente con JSON puro válido. Sin markdown. Sin texto antes ni después del JSON.',
        'CERO saltos de línea dentro de los strings del JSON. Todos los strings en una sola línea.',
    ] );
}

function viva_build_user_message( WP_REST_Request $req ) {
    $g = function ( $k ) use ( $req ) {
        $v = $req->get_param( $k );
        return sanitize_text_field( $v !== null ? $v : 'N/A' );
    };
    $modo = $g( 'modoCV' ) === 'manual' ? 'Sin CV - datos declarados' : 'Con CV adjunto';
    $msg  = "PERFIL A EVALUAR:\n";
    $msg .= "Nombre: {$g('nombre')} {$g('apellido')}\n";
    $msg .= "Email: {$g('email')}\n";
    $msg .= "País: {$g('pais')}\n";
    $msg .= "Profesión declarada: {$g('profesion')}\n";
    $msg .= "Edad: {$g('edad')}\n";
    $msg .= "Inglés (nivel declarado): {$g('ingles')}\n";
    $msg .= "Certificación de inglés: {$g('certTipo')} — Puntaje: {$g('certPuntaje')}\n";
    $msg .= "Experiencia laboral: {$g('experiencia')} años\n";
    $msg .= "Estado civil: {$g('estadoCivil')}\n";
    $msg .= "Pareja profesional: {$g('parejaProf')}\n";
    $msg .= "Inglés de la pareja: {$g('parejaIngles')}\n\n";
    $msg .= "Conexión con Australia: {$g('conexionAU')} (nunca/visita/estudió/trabajó)\n";
    $msg .= "Experiencia laboral en Australia: {$g('expAU')}\n";
    $msg .= "Estudio en Australia: {$g('estudioAU')}\n";
    $msg .= "Estudio en zona regional AU: {$g('estudioRegional')}\n";
    $msg .= "Professional Year: {$g('profYear')}\n";
    $msg .= "NAATI: {$g('naati')}\n\n";
    $msg .= "Plazo deseado: {$g('plazo')}\n";
    $msg .= "Capacidad de inversión: {$g('inversion')}\n";
    $msg .= "Nivel de decisión: {$g('decision')}/5\n\n";
    $msg .= "Modo de información: {$modo}\n";
    if ( $g( 'modoCV' ) === 'manual' ) {
        $msg .= "Título universitario: {$g('titulo')}\nPosgrado: {$g('postgrado')}\nEmpresa actual: {$g('empresa')}\nCargo: {$g('cargo')}\nActividades diarias: {$g('descripcion')}\n";
    }
    return $msg;
}



// ═══════════════════════════════════════════════════════════════
// SHORTCODE — [viva_pre_evm]
// ═══════════════════════════════════════════════════════════════
function viva_preevm_shortcode( $atts ) {
    // Detectar token de continuación (?continuar=TOKEN)
    $continue_token = sanitize_text_field( $_GET['continuar'] ?? '' );
    $continue_data  = null;
    if ( ! empty( $continue_token ) ) {
        $posts = get_posts( [
            'post_type'      => 'preevm_draft',
            'meta_key'       => '_preevm_draft_token',
            'meta_value'     => $continue_token,
            'posts_per_page' => 1,
        ] );
        if ( ! empty( $posts ) ) {
            $expiry = (int) get_post_meta( $posts[0]->ID, '_preevm_draft_expiry', true );
            if ( $expiry >= time() ) {
                $continue_data = json_decode( get_post_meta( $posts[0]->ID, '_preevm_draft_data', true ), true );
            }
        }
    }

    $init_config = wp_json_encode( [
        'restUrl'       => esc_url_raw( rest_url( 'viva/v1/' ) ),
        'nonce'         => wp_create_nonce( 'wp_rest' ),
        'siteUrl'       => get_site_url(),
        'continueToken' => $continue_token,
        'continueData'  => $continue_data,
    ] );

    ob_start();
    echo '<script>window._viva_preevm_cfg=' . $init_config . ';</script>';

    ob_start();
    echo <<<'VPHTML'

<style>
@import url('https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,600;0,700;1,400;1,600&family=Outfit:wght@300;400;500;600;700;800&display=swap');
#viva-preevm-app{
  --navy:#07111F;--navy2:#0D1E35;--navy3:#152A47;
  --orange:#E8600A;--orange2:#FF7120;--gold:#F5A623;
  --white:#FFFFFF;--gray:#7A8EA8;--border:rgba(255,255,255,.07);
  --green:#0FBE7C;--red:#E84545;--amber:#F59E0B;--surf:rgba(255,255,255,.035);
  font-family:'Outfit',sans-serif;
  background:var(--navy);
  color:#fff;
  min-height:100vh;
  overflow-x:hidden;
  position:relative;
}
#viva-preevm-app .vp-bg{position:absolute;inset:0;z-index:0;pointer-events:none;
  background:radial-gradient(ellipse 110% 70% at 80% -15%,rgba(232,96,10,.15) 0%,transparent 55%),
             radial-gradient(ellipse 70% 80% at -5% 100%,rgba(13,30,53,.95) 0%,transparent 65%);}
#viva-preevm-app .vp-bg-grid{position:absolute;inset:0;z-index:0;pointer-events:none;
  background-image:linear-gradient(rgba(255,255,255,.018) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,.018) 1px,transparent 1px);
  background-size:80px 80px;}
#viva-preevm-app .vp-bg-lines{position:absolute;inset:0;z-index:0;pointer-events:none;overflow:hidden;}
#viva-preevm-app .vp-bg-lines::before,#viva-preevm-app .vp-bg-lines::after{content:'';position:absolute;width:1px;
  background:linear-gradient(180deg,transparent,rgba(232,96,10,.18),transparent);top:0;bottom:0;}
#viva-preevm-app .vp-bg-lines::before{left:22%;}#viva-preevm-app .vp-bg-lines::after{right:22%;}
#viva-preevm-app .vp-wrap{position:relative;z-index:1;max-width:820px;margin:0 auto;padding:0 28px 80px;}
/* HEADER */
#viva-preevm-app header{padding:32px 0 0;display:flex;align-items:center;justify-content:space-between;}
#viva-preevm-app .vp-logo{height:48px;width:auto;object-fit:contain;}
#viva-preevm-app .vp-badge{background:rgba(232,96,10,.12);border:1px solid rgba(232,96,10,.3);color:var(--orange2);
  font-size:10.5px;font-weight:700;letter-spacing:1.2px;text-transform:uppercase;
  padding:6px 15px;border-radius:20px;display:flex;align-items:center;gap:7px;}
#viva-preevm-app .vp-badge-dot{width:6px;height:6px;background:var(--green);border-radius:50%;animation:vpBlink 2s infinite;}
@keyframes vpBlink{0%,100%{opacity:1;}50%{opacity:.25;}}
/* HERO */
#viva-preevm-app .vp-hero{padding:56px 0 40px;text-align:center;position:relative;}
#viva-preevm-app .vp-hero::before{content:'';position:absolute;left:50%;top:0;transform:translateX(-50%);
  width:1px;height:56px;background:linear-gradient(180deg,transparent,rgba(232,96,10,.4),transparent);}
#viva-preevm-app .vp-hero-tag{display:inline-flex;align-items:center;gap:9px;background:rgba(255,255,255,.04);
  border:1px solid var(--border);border-radius:30px;padding:8px 20px;
  font-size:12px;color:var(--gray);letter-spacing:.5px;margin-bottom:28px;}
#viva-preevm-app h1{font-family:'Cormorant Garamond',serif;font-size:clamp(38px,7vw,64px);
  font-weight:700;line-height:1.05;letter-spacing:-2px;margin-bottom:18px;}
#viva-preevm-app h1 strong{font-style:italic;color:var(--orange);
  background:linear-gradient(135deg,var(--orange),var(--gold));
  -webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;}
#viva-preevm-app .vp-hero-sub{font-size:16px;color:var(--gray);font-weight:300;line-height:1.7;
  max-width:520px;margin:0 auto 36px;}
/* STEPS INDICATOR */
#viva-preevm-app .vp-steps{display:flex;align-items:center;justify-content:center;gap:0;margin-bottom:40px;}
#viva-preevm-app .vp-step{display:flex;flex-direction:column;align-items:center;gap:7px;}
#viva-preevm-app .vp-sn{width:34px;height:34px;border-radius:50%;display:flex;align-items:center;justify-content:center;
  font-size:12px;font-weight:700;border:1.5px solid rgba(255,255,255,.1);
  color:var(--gray);background:rgba(255,255,255,.03);transition:all .35s;}
#viva-preevm-app .vp-sn.on{background:var(--orange);border-color:var(--orange);color:#fff;box-shadow:0 0 22px rgba(232,96,10,.5);}
#viva-preevm-app .vp-sn.ok{background:var(--green);border-color:var(--green);color:#fff;}
#viva-preevm-app .vp-sl{font-size:10px;color:var(--gray);white-space:nowrap;letter-spacing:.3px;}
#viva-preevm-app .vp-sl.on{color:var(--orange);font-weight:600;}
#viva-preevm-app .vp-sline{width:56px;height:1px;background:var(--border);margin-bottom:22px;}
/* CARD */
#viva-preevm-app .vp-card{background:var(--surf);border:1px solid var(--border);border-radius:24px;
  padding:40px;backdrop-filter:blur(16px);margin-bottom:16px;position:relative;overflow:hidden;}
#viva-preevm-app .vp-card::before{content:'';position:absolute;top:0;left:0;right:0;height:1px;
  background:linear-gradient(90deg,transparent,rgba(232,96,10,.4),transparent);}
/* CARD TITLE */
#viva-preevm-app .vp-card-title{font-family:'Cormorant Garamond',serif;font-size:26px;font-weight:700;
  letter-spacing:-.5px;margin-bottom:6px;}
#viva-preevm-app .vp-card-sub{font-size:14px;color:var(--gray);margin-bottom:28px;line-height:1.6;}
/* FORM */
#viva-preevm-app .vp-row{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;}
#viva-preevm-app .vp-row.one{grid-template-columns:1fr;}
#viva-preevm-app .vp-fld{display:flex;flex-direction:column;gap:8px;}
#viva-preevm-app label{font-size:10.5px;font-weight:700;color:var(--gray);letter-spacing:.8px;text-transform:uppercase;}
#viva-preevm-app input,#viva-preevm-app select{background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.09);
  border-radius:12px;padding:13px 16px;color:#fff;font-family:'Outfit',sans-serif;
  font-size:14.5px;outline:none;transition:border-color .2s,background .2s;
  width:100%;-webkit-appearance:none;box-sizing:border-box;}
#viva-preevm-app input:focus,#viva-preevm-app select:focus{border-color:var(--orange);background:rgba(232,96,10,.06);}
#viva-preevm-app input::placeholder{color:rgba(255,255,255,.25);}
#viva-preevm-app select option{background:#0D1E35;color:#fff;}
#viva-preevm-app input[readonly]{opacity:.6;cursor:not-allowed;}
#viva-preevm-app .vp-readonly-badge{font-size:10px;color:var(--green);font-weight:600;margin-top:3px;}
/* BUTTON */
#viva-preevm-app .vp-btn-primary{width:100%;background:linear-gradient(135deg,var(--orange),var(--orange2));
  color:#fff;border:none;border-radius:14px;padding:16px 24px;
  font-family:'Outfit',sans-serif;font-size:15px;font-weight:700;cursor:pointer;
  transition:all .25s;letter-spacing:.3px;margin-top:8px;display:flex;align-items:center;justify-content:center;gap:8px;}
#viva-preevm-app .vp-btn-primary:hover{transform:translateY(-2px);box-shadow:0 8px 30px rgba(232,96,10,.4);}
#viva-preevm-app .vp-btn-primary:disabled{opacity:.5;cursor:not-allowed;transform:none;}
#viva-preevm-app .vp-btn-sec{background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.12);
  color:rgba(255,255,255,.8);border-radius:14px;padding:14px 20px;font-family:'Outfit',sans-serif;
  font-size:14px;font-weight:600;cursor:pointer;transition:all .25s;width:100%;margin-top:8px;}
#viva-preevm-app .vp-btn-sec:hover{background:rgba(255,255,255,.1);}
#viva-preevm-app .vp-btn-prev{background:transparent;border:1px solid rgba(255,255,255,.1);
  color:var(--gray);border-radius:10px;padding:10px 18px;font-family:'Outfit',sans-serif;
  font-size:13px;cursor:pointer;transition:all .2s;}
#viva-preevm-app .vp-btn-prev:hover{border-color:rgba(255,255,255,.25);color:#fff;}
/* QUIZ */
#viva-preevm-app .vp-quiz-wrap{animation:vpFadeUp .3s ease;}
@keyframes vpFadeUp{from{opacity:0;transform:translateY(12px);}to{opacity:1;transform:none;}}
#viva-preevm-app .vp-quiz-q{font-size:18px;font-weight:600;margin-bottom:20px;line-height:1.4;}
#viva-preevm-app .vp-pills{display:flex;flex-wrap:wrap;gap:10px;margin-bottom:24px;}
#viva-preevm-app .vp-pill{padding:11px 20px;background:rgba(255,255,255,.04);border:1.5px solid rgba(255,255,255,.09);
  border-radius:40px;font-family:'Outfit',sans-serif;font-size:14px;cursor:pointer;
  transition:all .2s;color:rgba(255,255,255,.8);}
#viva-preevm-app .vp-pill:hover{border-color:rgba(232,96,10,.5);background:rgba(232,96,10,.06);color:#fff;}
#viva-preevm-app .vp-pill.selected{border-color:var(--orange);background:rgba(232,96,10,.15);color:#fff;font-weight:600;}
#viva-preevm-app .vp-pill.selected::after{content:' ✓';}
#viva-preevm-app .vp-quiz-progress{height:3px;background:rgba(255,255,255,.06);border-radius:2px;margin-bottom:28px;overflow:hidden;}
#viva-preevm-app .vp-qp-bar{height:100%;background:linear-gradient(90deg,var(--orange),var(--orange2));border-radius:2px;transition:width .4s ease;}
/* SCALE 1-5 */
#viva-preevm-app .vp-scale{display:flex;gap:8px;align-items:center;margin-bottom:16px;}
#viva-preevm-app .vp-scale-btn{width:52px;height:52px;border-radius:12px;border:1.5px solid rgba(255,255,255,.09);
  background:rgba(255,255,255,.04);color:rgba(255,255,255,.7);font-size:18px;font-weight:700;cursor:pointer;
  transition:all .2s;display:flex;align-items:center;justify-content:center;font-family:'Outfit',sans-serif;}
#viva-preevm-app .vp-scale-btn:hover{border-color:rgba(232,96,10,.4);background:rgba(232,96,10,.08);}
#viva-preevm-app .vp-scale-btn.selected{background:var(--orange);border-color:var(--orange);color:#fff;box-shadow:0 4px 16px rgba(232,96,10,.4);}
#viva-preevm-app .vp-scale-labels{display:flex;justify-content:space-between;font-size:11px;color:var(--gray);margin-top:4px;}
/* CV DROP */
#viva-preevm-app .vp-drop{border:2px dashed rgba(255,255,255,.12);border-radius:18px;padding:48px 24px;
  text-align:center;cursor:pointer;transition:all .25s;position:relative;margin-bottom:16px;}
#viva-preevm-app .vp-drop.over{border-color:var(--orange);background:rgba(232,96,10,.06);}
#viva-preevm-app .vp-drop.ok{border-color:var(--green);background:rgba(15,190,124,.05);}
#viva-preevm-app .vp-drop-ico{font-size:40px;margin-bottom:12px;}
#viva-preevm-app .vp-drop-title{font-size:16px;font-weight:600;margin-bottom:6px;}
#viva-preevm-app .vp-drop-sub{font-size:13px;color:var(--gray);}
#viva-preevm-app .vp-drop input[type=file]{position:absolute;inset:0;opacity:0;cursor:pointer;width:100%;height:100%;}
#viva-preevm-app .vp-cv-tips{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:20px;}
#viva-preevm-app .vp-cv-tip{font-size:12.5px;color:var(--gray);padding:8px 12px;background:rgba(255,255,255,.03);border-radius:8px;}
#viva-preevm-app .vp-cv-alts{text-align:center;margin-top:16px;padding-top:16px;border-top:1px solid var(--border);}
#viva-preevm-app .vp-cv-alts a{color:var(--gray);font-size:13px;text-decoration:none;cursor:pointer;display:block;margin-bottom:8px;}
#viva-preevm-app .vp-cv-alts a:hover{color:var(--orange);}
#viva-preevm-app .vp-manual-banner{display:flex;align-items:flex-start;gap:12px;background:rgba(245,158,11,.08);
  border:1px solid rgba(245,158,11,.2);border-radius:12px;padding:14px 16px;margin-bottom:16px;margin-top:12px;font-size:13px;}
/* LOADING */
#viva-preevm-app .vp-load-center{text-align:center;padding:20px 0 30px;}
#viva-preevm-app .vp-roo-spin{font-size:52px;display:block;animation:vpRooSpin 1.2s infinite;margin-bottom:16px;}
@keyframes vpRooSpin{0%,100%{transform:rotate(-8deg) scale(1);}50%{transform:rotate(8deg) scale(1.1);}}
#viva-preevm-app .vp-load-title{font-size:22px;font-weight:700;margin-bottom:8px;}
#viva-preevm-app .vp-load-sub{color:var(--gray);font-size:14px;}
#viva-preevm-app .vp-load-steps{margin-top:24px;}
#viva-preevm-app .ls{padding:12px 16px;border-radius:10px;font-size:14px;color:var(--gray);
  background:rgba(255,255,255,.025);border:1px solid transparent;margin-bottom:8px;
  display:flex;align-items:center;gap:10px;transition:all .3s;}
#viva-preevm-app .ls.active{background:rgba(232,96,10,.08);border-color:rgba(232,96,10,.2);color:rgba(255,255,255,.9);}
#viva-preevm-app .ls.done{background:rgba(15,190,124,.08);border-color:rgba(15,190,124,.2);color:rgba(255,255,255,.9);}
#viva-preevm-app .ls-ico{font-size:16px;flex-shrink:0;}
/* VERDICT */
#viva-preevm-app .vp-verdict{display:flex;align-items:center;gap:16px;padding:20px 24px;border-radius:16px;margin-bottom:24px;}
#viva-preevm-app .vp-verdict.apto{background:rgba(15,190,124,.1);border:1px solid rgba(15,190,124,.25);}
#viva-preevm-app .vp-verdict.parcial{background:rgba(245,158,11,.09);border:1px solid rgba(245,158,11,.25);}
#viva-preevm-app .vp-verdict.noapto{background:rgba(245,158,11,.09);border:1px solid rgba(245,158,11,.25);}
#viva-preevm-app .vp-v-ico{font-size:28px;flex-shrink:0;}
#viva-preevm-app .vp-v-name{font-weight:700;font-size:17px;margin-bottom:3px;}
#viva-preevm-app .vp-v-tag{font-size:13px;color:var(--gray);}
/* DISCLAIMER */
#viva-preevm-app .vp-disclaimer{background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.2);
  border-radius:12px;padding:14px 18px;font-size:13px;line-height:1.65;
  color:rgba(255,255,255,.7);margin-bottom:20px;}
/* SCORES */
#viva-preevm-app .vp-scores{display:grid;grid-template-columns:repeat(3,1fr);gap:13px;margin-bottom:24px;}
#viva-preevm-app .vp-sc{background:rgba(255,255,255,.04);border:1px solid var(--border);border-radius:14px;padding:18px;}
#viva-preevm-app .vp-sc-lbl{font-size:11px;color:var(--gray);text-transform:uppercase;letter-spacing:.8px;margin-bottom:8px;}
#viva-preevm-app .vp-sc-val{font-size:26px;font-weight:800;margin-bottom:8px;}
#viva-preevm-app .bar{height:4px;background:rgba(255,255,255,.06);border-radius:2px;overflow:hidden;}
#viva-preevm-app .bar-f{height:100%;border-radius:2px;transition:width 1s ease;}
/* DESGLOSE */
#viva-preevm-app .vp-desglose{background:rgba(255,255,255,.03);border:1px solid var(--border);border-radius:14px;padding:20px;margin-bottom:20px;}
#viva-preevm-app .vp-desglose-title{font-size:13px;font-weight:700;letter-spacing:.5px;text-transform:uppercase;color:var(--orange);margin-bottom:14px;}
#viva-preevm-app .vp-desglose table{width:100%;border-collapse:collapse;font-size:13px;}
#viva-preevm-app .vp-desglose th{text-align:left;color:var(--gray);font-size:10.5px;letter-spacing:.5px;text-transform:uppercase;padding:0 8px 10px;border-bottom:1px solid var(--border);}
#viva-preevm-app .vp-desglose td{padding:8px 8px;border-bottom:1px solid rgba(255,255,255,.04);vertical-align:top;}
#viva-preevm-app .vp-desglose td:last-child{text-align:right;white-space:nowrap;}
#viva-preevm-app .vp-desglose .pts-pos{color:var(--green);font-weight:700;}
#viva-preevm-app .vp-desglose .pts-zero{color:var(--gray);}
#viva-preevm-app .vp-desglose tfoot td{font-weight:700;color:var(--orange);border-top:2px solid rgba(232,96,10,.3);border-bottom:none;padding-top:12px;}
#viva-preevm-app .vp-desglose-nota{font-size:12px;color:var(--gray);margin-top:10px;line-height:1.6;}
/* SECTIONS */
#viva-preevm-app .vp-div{height:1px;background:var(--border);margin:24px 0;}
#viva-preevm-app .vp-sec{margin-bottom:18px;}
#viva-preevm-app .vp-sec-h{font-size:15px;font-weight:700;margin-bottom:10px;display:flex;align-items:center;gap:8px;}
#viva-preevm-app .vp-sec-num{width:26px;height:26px;border-radius:50%;background:rgba(232,96,10,.12);
  border:1px solid rgba(232,96,10,.25);color:var(--orange);
  display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;flex-shrink:0;}
#viva-preevm-app .vp-sec-body{font-size:14px;color:rgba(255,255,255,.72);line-height:1.75;}
/* ANZSCO */
#viva-preevm-app .vp-az{display:flex;align-items:flex-start;gap:12px;padding:14px;
  background:rgba(255,255,255,.03);border-radius:10px;margin-bottom:8px;}
#viva-preevm-app .vp-az-code{font-family:monospace;font-size:13px;background:rgba(232,96,10,.15);
  color:var(--orange);padding:4px 8px;border-radius:6px;font-weight:700;white-space:nowrap;}
#viva-preevm-app .vp-az-name{font-size:14px;font-weight:600;margin-bottom:3px;}
#viva-preevm-app .vp-az-note{font-size:12px;color:var(--gray);}
/* SHORTAGE MAP */
#viva-preevm-app .vp-shortage-wrap{margin-top:16px;border-top:1px solid rgba(255,255,255,.06);padding-top:16px}
#viva-preevm-app .vp-shortage-title{font-size:12px;font-weight:700;color:var(--gray);text-transform:uppercase;letter-spacing:.7px;margin-bottom:12px}
#viva-preevm-app .vp-shortage-occ{background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.06);border-radius:12px;padding:14px;margin-bottom:10px}
#viva-preevm-app .vp-shortage-occ-h{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:10px}
#viva-preevm-app .vp-shortage-occ-name{font-size:13px;font-weight:600}
#viva-preevm-app .vp-shortage-nat{font-size:11px;color:var(--gray);margin-left:auto}
#viva-preevm-app .vp-shortage-states{display:grid;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:6px;margin-bottom:10px}
#viva-preevm-app .vp-ss{display:flex;align-items:center;gap:5px;background:rgba(255,255,255,.04);border-radius:8px;padding:5px 9px;font-size:11px}
#viva-preevm-app .vp-ss.s .vp-ss-code{color:#0FBE7C;font-weight:700}
#viva-preevm-app .vp-ss.r .vp-ss-code{color:#60A5FA;font-weight:700}
#viva-preevm-app .vp-ss.m .vp-ss-code{color:#F59E0B;font-weight:700}
#viva-preevm-app .vp-ss.ns .vp-ss-code{color:var(--gray)}
#viva-preevm-app .vp-ss-lbl{color:var(--gray);font-size:10px;flex:1;text-align:right}
#viva-preevm-app .vp-shortage-demand{font-size:12px;color:rgba(255,255,255,.6);margin-bottom:8px}
#viva-preevm-app .vp-shortage-jsa a{font-size:11px;color:var(--orange);text-decoration:none}
/* VARIABLES */
#viva-preevm-app .vp-vari{display:flex;align-items:flex-start;gap:12px;padding:14px;
  background:rgba(255,255,255,.03);border-radius:10px;margin-bottom:8px;}
#viva-preevm-app .vp-vari-ico{width:36px;height:36px;border-radius:10px;background:rgba(232,96,10,.1);
  display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0;}
#viva-preevm-app .vp-vari-t{font-size:14px;font-weight:600;margin-bottom:3px;}
#viva-preevm-app .vp-vari-d{font-size:13px;color:var(--gray);line-height:1.55;}
/* TAGS */
#viva-preevm-app .vp-tags{display:flex;flex-wrap:wrap;gap:8px;margin-top:4px;}
#viva-preevm-app .vp-tag{padding:5px 14px;border-radius:20px;font-size:12.5px;font-weight:600;}
#viva-preevm-app .vp-tag.v{background:rgba(232,96,10,.15);border:1px solid rgba(232,96,10,.3);color:var(--orange2);}
/* BLOCKERS & RECOM */
#viva-preevm-app .vp-blocker{display:flex;align-items:flex-start;gap:12px;padding:14px;
  background:rgba(245,158,11,.07);border:1px solid rgba(245,158,11,.15);border-radius:10px;margin-bottom:8px;}
#viva-preevm-app .vp-blocker-ico{font-size:22px;flex-shrink:0;}
#viva-preevm-app .vp-blocker-t{font-size:14px;font-weight:600;margin-bottom:3px;color:var(--amber);}
#viva-preevm-app .vp-blocker-d{font-size:13px;color:rgba(255,255,255,.7);line-height:1.55;}
#viva-preevm-app .vp-recom-item{display:flex;gap:10px;padding:12px 14px;
  background:rgba(255,255,255,.03);border-radius:10px;margin-bottom:8px;font-size:14px;line-height:1.6;}
/* RECOM */
#viva-preevm-app .vp-recom-item span{font-size:18px;flex-shrink:0;}
/* NOAPTO SCORES */
#viva-preevm-app .vp-noapt-score{display:flex;justify-content:center;gap:20px;margin:24px 0;flex-wrap:wrap;}
#viva-preevm-app .vp-noapt-sc{text-align:center;padding:16px 24px;background:rgba(255,255,255,.04);border-radius:14px;}
#viva-preevm-app .vp-noapt-sc-lbl{font-size:11px;color:var(--gray);text-transform:uppercase;letter-spacing:.8px;margin-bottom:8px;}
#viva-preevm-app .vp-noapt-sc-val{font-size:26px;font-weight:800;color:var(--amber);}
/* CTA */
#viva-preevm-app .vp-cta{background:rgba(255,255,255,.03);border:1px solid var(--border);border-radius:18px;padding:28px;text-align:center;margin-top:24px;}
#viva-preevm-app .vp-cta h3{font-size:18px;font-weight:700;margin-bottom:10px;}
#viva-preevm-app .vp-cta p{font-size:14px;color:var(--gray);margin-bottom:20px;line-height:1.6;}
#viva-preevm-app .vp-cta-btns{display:flex;flex-direction:column;gap:10px;}
#viva-preevm-app .vp-btn-cta{display:inline-flex;align-items:center;justify-content:center;gap:8px;
  background:linear-gradient(135deg,var(--orange),var(--orange2));color:#fff;border:none;
  border-radius:14px;padding:16px 24px;font-family:'Outfit',sans-serif;font-size:15px;
  font-weight:700;cursor:pointer;text-decoration:none;transition:all .25s;}
#viva-preevm-app .vp-btn-cta:hover{transform:translateY(-2px);box-shadow:0 8px 30px rgba(232,96,10,.4);}
#viva-preevm-app .vp-btn-wa{background:linear-gradient(135deg,#25D366,#128C7E);color:#fff;border:none;
  border-radius:14px;padding:15px 24px;font-family:'Outfit',sans-serif;font-size:15px;
  font-weight:700;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;justify-content:center;gap:8px;transition:all .25s;}
#viva-preevm-app .vp-btn-wa:hover{transform:translateY(-2px);box-shadow:0 8px 24px rgba(37,211,102,.35);}
#viva-preevm-app .vp-btn-reset{background:transparent;border:1px solid rgba(255,255,255,.1);color:var(--gray);
  border-radius:12px;padding:11px 22px;font-family:'Outfit',sans-serif;font-size:13px;cursor:pointer;
  display:block;margin:20px auto 0;transition:all .2s;}
#viva-preevm-app .vp-btn-reset:hover{border-color:rgba(255,255,255,.25);color:#fff;}
/* CALENDAR IFRAME */
#viva-preevm-app .vp-cal-wrap{margin-top:24px;border-radius:14px;overflow:hidden;background:#fff;}
#viva-preevm-app .vp-cal-wrap iframe{width:100%;border:none;display:block;min-height:700px;height:750px;transition:height .3s ease;}
/* LEGAL */
#viva-preevm-app .vp-legal{font-size:11px;color:rgba(255,255,255,.25);text-align:center;margin-top:20px;line-height:1.7;}
/* GHL GREETING */
#viva-preevm-app .vp-ghl-greeting{background:rgba(15,190,124,.07);border:1px solid rgba(15,190,124,.2);
  border-radius:14px;padding:16px 20px;margin-bottom:20px;display:none;}
#viva-preevm-app .vp-ghl-greeting.show{display:block;}
#viva-preevm-app .vp-ghl-name{font-size:20px;font-weight:700;margin-bottom:4px;}
#viva-preevm-app .vp-ghl-sub{font-size:13px;color:var(--gray);}
/* CONFIRM SCREEN */
#viva-preevm-app .vp-confirm-ico{font-size:56px;text-align:center;margin-bottom:16px;}
#viva-preevm-app .vp-confirm-title{font-size:22px;font-weight:700;text-align:center;margin-bottom:10px;}
#viva-preevm-app .vp-confirm-sub{font-size:15px;color:var(--gray);text-align:center;line-height:1.7;margin-bottom:20px;}
#viva-preevm-app .vp-confirm-tip{background:rgba(255,255,255,.04);border-radius:10px;padding:14px 18px;font-size:13px;color:var(--gray);text-align:center;line-height:1.6;}
/* RESULT ONLINE BANNER */
#viva-preevm-app .vp-result-url-banner{background:rgba(232,96,10,.07);border:1px solid rgba(232,96,10,.2);
  border-radius:12px;padding:14px 18px;margin-bottom:20px;font-size:13px;display:flex;align-items:center;gap:10px;}
#viva-preevm-app .vp-result-url-banner a{color:var(--orange);text-decoration:none;word-break:break-all;}
/* DISPLAY STATES */
#viva-preevm-app [data-screen]{display:none;}
#viva-preevm-app [data-screen].active{display:block;}
/* RESPONSIVE */
@media(max-width:600px){
  #viva-preevm-app .vp-wrap{padding:0 16px 60px;}
  #viva-preevm-app .vp-card{padding:24px 20px;}
  #viva-preevm-app .vp-row{grid-template-columns:1fr;}
  #viva-preevm-app .vp-scores{grid-template-columns:1fr 1fr;}
  #viva-preevm-app .vp-steps{gap:0;}
  #viva-preevm-app .vp-sline{width:28px;}
  #viva-preevm-app .vp-sl{font-size:8px;}
  #viva-preevm-app .vp-desglose table{font-size:12px;}
  #viva-preevm-app .vp-cal-wrap iframe{min-height:600px;height:680px;}
}
</style>


<div id="viva-preevm-app">
<div class="vp-bg"></div>
<div class="vp-bg-grid"></div>
<div class="vp-bg-lines"></div>
<div class="vp-wrap">

<!-- HEADER -->
<header>
  <img class="vp-logo" src="data:image/png;base64,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AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA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ah0iuXgczhVFRPqt/UaRAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA//Z" alt="Viva Australia">
  <div class="vp-badge"><span class="vp-badge-dot"></span> Pre-EVM Gratuita</div>
</header>

<!-- HERO -->
<div class="vp-hero">
  <div class="vp-hero-tag"><span>&#x1F998;</span> Evaluaci&oacute;n de Viabilidad Migratoria</div>
  <h1>Tu perfil <strong>australiano</strong><br>en minutos</h1>
  <p class="vp-hero-sub">Nuestro sistema de IA analiza tu CV y perfil contra el sistema de puntos SkillSelect 2025&#x2013;26 para darte un informe real &mdash; no gen&eacute;rico.</p>
</div>

<!-- STEPS INDICATOR -->
<div class="vp-steps" id="vpSteps">
  <div class="vp-step"><div class="vp-sn on" id="vpSN1">1</div><div class="vp-sl on" id="vpSL1">Email</div></div>
  <div class="vp-sline"></div>
  <div class="vp-step"><div class="vp-sn" id="vpSN2">2</div><div class="vp-sl" id="vpSL2">Perfil</div></div>
  <div class="vp-sline"></div>
  <div class="vp-step"><div class="vp-sn" id="vpSN3">3</div><div class="vp-sl" id="vpSL3">Quiz &#x1F998;</div></div>
  <div class="vp-sline"></div>
  <div class="vp-step"><div class="vp-sn" id="vpSN4">4</div><div class="vp-sl" id="vpSL4">CV</div></div>
  <div class="vp-sline"></div>
  <div class="vp-step"><div class="vp-sn" id="vpSN5">5</div><div class="vp-sl" id="vpSL5">Resultado</div></div>
</div>

<!-- ═══ PASO 1: EMAIL ═══ -->
<div class="vp-card" data-screen="s1">
  <div class="vp-card-title">&#x1F30F; &iquest;Desde d&oacute;nde nos escribes?</div>
  <div class="vp-card-sub">Empezamos por tu email &mdash; es todo lo que necesitamos por ahora.</div>
  <div class="vp-row one">
    <div class="vp-fld">
      <label>EMAIL</label>
      <input type="email" id="vpEmail" placeholder="tu@email.com">
    </div>
  </div>
  <button class="vp-btn-primary" onclick="vpHandleStep1()">Continuar &#x2192;</button>
</div>

<!-- ═══ PASO 2: DATOS DEL PERFIL ═══ -->
<div class="vp-card" data-screen="s2" style="display:none">
  <div class="vp-ghl-greeting" id="vpGHLGreeting">
    <div class="vp-ghl-name">&#x1F44B; <span id="vpGHLFirstName"></span></div>
    <div class="vp-ghl-sub">Encontramos tu perfil &mdash; algunos datos ya est&aacute;n precargados.</div>
  </div>
  <div class="vp-card-title">Tu perfil profesional</div>
  <div class="vp-card-sub">Cu&eacute;ntanos sobre ti para poder analizar tu caso correctamente.</div>
  <div class="vp-row">
    <div class="vp-fld">
      <label>NOMBRE</label>
      <input type="text" id="vpNombre" placeholder="Tu nombre">
      <div class="vp-readonly-badge" id="vpNombreBadge" style="display:none">&#x2714; Dato guardado en CRM</div>
    </div>
    <div class="vp-fld">
      <label>APELLIDO</label>
      <input type="text" id="vpApellido" placeholder="Tu apellido">
      <div class="vp-readonly-badge" id="vpApellidoBadge" style="display:none">&#x2714; Dato guardado en CRM</div>
    </div>
  </div>
  <div class="vp-row">
    <div class="vp-fld">
      <label>WHATSAPP (con c&oacute;digo de pa&iacute;s)</label>
      <input type="tel" id="vpWA" placeholder="+57 300 123 4567">
    </div>
    <div class="vp-fld">
      <label>PA&Iacute;S DE RESIDENCIA</label>
      <select id="vpPais">
        <option value="">Seleccionar pa&iacute;s...</option>
        <option value="CO">&#x1F1E8;&#x1F1F4; Colombia</option>
        <option value="MX">&#x1F1F2;&#x1F1FD; M&eacute;xico</option>
        <option value="AR">&#x1F1E6;&#x1F1F7; Argentina</option>
        <option value="PE">&#x1F1F5;&#x1F1EA; Per&uacute;</option>
        <option value="CL">&#x1F1E8;&#x1F1F1; Chile</option>
        <option value="EC">&#x1F1EA;&#x1F1E8; Ecuador</option>
        <option value="VE">&#x1F1FB;&#x1F1EA; Venezuela</option>
        <option value="BO">&#x1F1E7;&#x1F1F4; Bolivia</option>
        <option value="PY">&#x1F1F5;&#x1F1FE; Paraguay</option>
        <option value="UY">&#x1F1FA;&#x1F1FE; Uruguay</option>
        <option value="BR">&#x1F1E7;&#x1F1F7; Brasil</option>
        <option value="GT">&#x1F1EC;&#x1F1F9; Guatemala</option>
        <option value="SV">&#x1F1F8;&#x1F1FB; El Salvador</option>
        <option value="HN">&#x1F1ED;&#x1F1F3; Honduras</option>
        <option value="NI">&#x1F1F3;&#x1F1EE; Nicaragua</option>
        <option value="CR">&#x1F1E8;&#x1F1F7; Costa Rica</option>
        <option value="PA">&#x1F1F5;&#x1F1E6; Panam&aacute;</option>
        <option value="DO">&#x1F1E9;&#x1F1F4; Rep. Dominicana</option>
        <option value="CU">&#x1F1E8;&#x1F1FA; Cuba</option>
        <option value="ES">&#x1F1EA;&#x1F1F8; Espa&ntilde;a</option>
        <option value="US">&#x1F1FA;&#x1F1F8; Estados Unidos</option>
        <option value="OTHER">Otro</option>
      </select>
    </div>
  </div>
  <div class="vp-row one">
    <div class="vp-fld">
      <label>PROFESI&Oacute;N / CARRERA</label>
      <input type="text" id="vpProfesion" placeholder="Ej: Ingeniero de sistemas, Contadora, M&eacute;dico...">
    </div>
  </div>
  <div class="vp-row">
    <div class="vp-fld">
      <label>RANGO DE EDAD</label>
      <select id="vpEdad">
        <option value="">Seleccionar...</option>
        <option value="18-24">18-24 a&ntilde;os</option>
        <option value="25-32">25-32 a&ntilde;os</option>
        <option value="33-39">33-39 a&ntilde;os</option>
        <option value="40-44">40-44 a&ntilde;os</option>
        <option value="45+">45+ a&ntilde;os</option>
      </select>
    </div>
    <div class="vp-fld">
      <label>NIVEL DE INGL&Eacute;S</label>
      <select id="vpIngles">
        <option value="">Seleccionar...</option>
        <option value="ninguno">Ninguno</option>
        <option value="basico">B&aacute;sico</option>
        <option value="intermedio">Intermedio</option>
        <option value="avanzado">Avanzado</option>
        <option value="certificado">Certificado (IELTS / PTE)</option>
      </select>
    </div>
  </div>
  <div class="vp-row one">
    <div class="vp-fld">
      <label>A&Ntilde;OS DE EXPERIENCIA POST-T&Iacute;TULO</label>
      <select id="vpExp">
        <option value="">Seleccionar...</option>
        <option value="0-2">0-2 a&ntilde;os</option>
        <option value="3-4">3-4 a&ntilde;os</option>
        <option value="5-7">5-7 a&ntilde;os</option>
        <option value="8+">8+ a&ntilde;os</option>
      </select>
    </div>
  </div>
  <div style="display:flex;gap:10px;margin-top:8px;">
    <button class="vp-btn-prev" onclick="vpGoScreen('s1')">&#x2190; Volver</button>
    <button class="vp-btn-primary" style="flex:1" onclick="vpHandleStep2()">Siguiente &#x2192;</button>
  </div>
</div>

<!-- ═══ PASO 3: QUIZ ═══ -->
<div class="vp-card" data-screen="s3" style="display:none">
  <div class="vp-card-title">&#x1F998; Quiz de migraci&oacute;n</div>
  <div class="vp-card-sub">Unas preguntas r&aacute;pidas para afinar tu an&aacute;lisis &mdash; no son un formulario de gobierno, te lo prometemos.</div>
  <div class="vp-quiz-progress"><div class="vp-qp-bar" id="vpQpBar"></div></div>
  <div id="vpQuizContainer"></div>
  <div style="display:flex;gap:10px;margin-top:16px;">
    <button class="vp-btn-prev" id="vpQuizPrevBtn" onclick="vpQuizPrev()">&#x2190; Anterior</button>
    <button class="vp-btn-primary" style="flex:1" id="vpQuizNextBtn" onclick="vpQuizNext()">Siguiente &#x2192;</button>
  </div>
</div>

<!-- ═══ PASO 4: CV ═══ -->
<div class="vp-card" data-screen="s4" style="display:none">
  <div class="vp-card-title" id="vpS4Title">&#x1F4C4; &Uacute;ltimo paso: tu hoja de vida</div>
  <div class="vp-card-sub" id="vpS4Sub">Tu CV es la pieza clave del an&aacute;lisis. Con &eacute;l, nuestra IA puede identificar tus c&oacute;digos ANZSCO, evaluar tu experiencia real y generar un informe personalizado.</div>

  <!-- Zona de drag & drop -->
  <div class="vp-drop" id="vpDrop">
    <input type="file" id="vpCvInput" accept=".pdf,.doc,.docx,.txt">
    <div class="vp-drop-ico" id="vpDropIco">&#x1F4C4;</div>
    <div class="vp-drop-title" id="vpDropTitle">Arrastra tu CV aqu&iacute; o haz clic para subir</div>
    <div class="vp-drop-sub" id="vpDropSub">PDF recomendado &middot; DOC, DOCX o TXT &middot; M&aacute;x. 10 MB</div>
  </div>

  <div class="vp-cv-tips">
    <div class="vp-cv-tip">&#x2705; PDF funciona mejor</div>
    <div class="vp-cv-tip">&#x1F4CB; Incluye t&iacute;tulos y experiencia</div>
    <div class="vp-cv-tip">&#x1F512; Datos 100% confidenciales</div>
    <div class="vp-cv-tip">&#x26A1; An&aacute;lisis en ~60 segundos</div>
  </div>

  <button class="vp-btn-primary" id="vpBtnAnalizar" onclick="vpHandleAnalyze()">&#x1F50D; Analizar mi perfil &#x2192;</button>

  <div class="vp-cv-alts">
    <a onclick="vpContinueLater()">&#x1F517; &iquest;No tienes tu CV a la mano? &rarr; Continuar despu&eacute;s</a>
    <a onclick="vpShowManualFields()" style="font-size:12px;color:#4A5E78;">&iquest;No tienes CV? Completar datos manualmente</a>
  </div>

  <!-- Campos manuales (ocultos por defecto) -->
  <div id="vpManualFields" style="display:none;margin-top:20px;padding-top:20px;border-top:1px solid rgba(255,255,255,.07);">
    <div class="vp-manual-banner">
      <span>&#x26A0;&#xFE0F;</span>
      <div>Sin CV, el an&aacute;lisis ser&aacute; menos preciso. Te recomendamos subirlo cuando est&eacute; disponible.</div>
    </div>
    <div class="vp-row one"><div class="vp-fld"><label>T&Iacute;TULO UNIVERSITARIO</label><input type="text" id="vpTitulo" placeholder="Ej: Ingeniero de Sistemas"></div></div>
    <div class="vp-row one"><div class="vp-fld"><label>POSGRADO (opcional)</label><input type="text" id="vpPostgrado" placeholder="Ej: MBA, Maestr&iacute;a en..."></div></div>
    <div class="vp-row">
      <div class="vp-fld"><label>EMPRESA ACTUAL</label><input type="text" id="vpEmpresa" placeholder="Nombre de la empresa"></div>
      <div class="vp-fld"><label>CARGO</label><input type="text" id="vpCargo" placeholder="Tu cargo actual"></div>
    </div>
    <div class="vp-row one"><div class="vp-fld"><label>DESCRIPCI&Oacute;N DE ACTIVIDADES DIARIAS</label>
      <input type="text" id="vpDesc" placeholder="&iquest;Qu&eacute; haces todos los d&iacute;as en tu trabajo?"></div></div>
  </div>
</div>

<!-- ═══ PASO 4b: POST-CV (Professional Year / NAATI) ═══ -->
<div class="vp-card" data-screen="s4b" style="display:none">
  <div class="vp-card-title">&#x1F4CB; Ya le&iacute;mos tu CV</div>
  <div class="vp-card-sub">Solo dos preguntas m&aacute;s que <strong>no aparecen en ning&uacute;n CV</strong> pero valen puntos importantes:</div>
  <div class="vp-quiz-wrap">
    <div class="vp-quiz-q">&#x2705; &iquest;Completaste un "Professional Year" en Australia?<br><small style="color:#7A8EA8;font-size:13px;font-weight:400">(Programa de 12 meses en ocupaci&oacute;n nominada, acreditado por Home Affairs)</small></div>
    <div class="vp-pills" id="vpPY_pills">
      <button class="vp-pill" onclick="vpSelectPill(this,'profYear','si','vpPY_pills')">S&iacute;</button>
      <button class="vp-pill" onclick="vpSelectPill(this,'profYear','no','vpPY_pills')">No</button>
      <button class="vp-pill" onclick="vpSelectPill(this,'profYear','que_es','vpPY_pills')">&#x2753; &iquest;Qu&eacute; es eso?</button>
    </div>
    <div class="vp-quiz-q" style="margin-top:20px">&#x1F5E3;&#xFE0F; &iquest;Tienes credencial NAATI de idioma comunitario?<br><small style="color:#7A8EA8;font-size:13px;font-weight:400">(Acreditaci&oacute;n como int&eacute;rprete de idioma, otorgada por NAATI Australia)</small></div>
    <div class="vp-pills" id="vpNAATI_pills">
      <button class="vp-pill" onclick="vpSelectPill(this,'naati','si','vpNAATI_pills')">S&iacute;</button>
      <button class="vp-pill" onclick="vpSelectPill(this,'naati','no','vpNAATI_pills')">No</button>
      <button class="vp-pill" onclick="vpSelectPill(this,'naati','que_es','vpNAATI_pills')">&#x2753; &iquest;Qu&eacute; es eso?</button>
    </div>
  </div>
  <button class="vp-btn-primary" onclick="vpLaunchAnalysis()" style="margin-top:20px">Generar mi an&aacute;lisis &#x1F998;</button>
</div>

<!-- ═══ LOADING ═══ -->
<div class="vp-card" data-screen="s-loading" style="display:none">
  <div class="vp-load-center">
    <span class="vp-roo-spin">&#x1F998;</span>
    <div class="vp-load-title">Analizando tu perfil...</div>
    <div class="vp-load-sub">Nuestra IA especializada en migraci&oacute;n australiana est&aacute; trabajando</div>
  </div>
  <div class="vp-load-steps">
    <div class="ls" id="ls1"><span class="ls-ico">&#9675;</span> Leyendo y extrayendo datos del perfil</div>
    <div class="ls" id="ls2"><span class="ls-ico">&#9675;</span> Identificando profesi&oacute;n y c&oacute;digos ANZSCO</div>
    <div class="ls" id="ls3"><span class="ls-ico">&#9675;</span> Calculando puntaje SkillSelect estimado</div>
    <div class="ls" id="ls4"><span class="ls-ico">&#9675;</span> Evaluando visas aplicables</div>
    <div class="ls" id="ls5"><span class="ls-ico">&#9675;</span> Guardando resultados en CRM</div>
    <div class="ls" id="ls6"><span class="ls-ico">&#9675;</span> Generando informe personalizado</div>
  </div>
</div>

<!-- ═══ RESULTADO: APTO / PARCIAL ═══ -->
<div class="vp-card" data-screen="s-result" style="display:none">
  <!-- Disclaimer ANTES del informe -->
  <div class="vp-disclaimer">&#x26A0;&#xFE0F; Este an&aacute;lisis fue generado por inteligencia artificial y tiene car&aacute;cter 100% orientativo. Puede contener imprecisiones. Solo un agente migratorio registrado (MARA) puede confirmar tu elegibilidad real. Te recomendamos agendar una asesor&iacute;a gratuita para validar estos resultados.</div>
  <!-- Verdict -->
  <div class="vp-verdict" id="vpVerdictBar">
    <div class="vp-v-ico" id="vpVIco"></div>
    <div><div class="vp-v-name" id="vpVName"></div><div class="vp-v-tag" id="vpVTag"></div></div>
  </div>
  <!-- Link al resultado online -->
  <div class="vp-result-url-banner" id="vpResultUrlBanner" style="display:none">
    &#x1F517; Ver este informe online: <a id="vpResultUrlLink" href="#" target="_blank"></a>
  </div>
  <!-- Scores -->
  <div class="vp-scores">
    <div class="vp-sc"><div class="vp-sc-lbl">Puntaje estimado</div><div class="vp-sc-val" id="vpScPts" style="color:#E8600A">-</div><div class="bar"><div class="bar-f" id="vpBPts" style="background:#E8600A;width:0%"></div></div></div>
    <div class="vp-sc"><div class="vp-sc-lbl">Viabilidad</div><div class="vp-sc-val" id="vpScVia" style="color:#0FBE7C">-</div><div class="bar"><div class="bar-f" id="vpBVia" style="background:#0FBE7C;width:0%"></div></div></div>
    <div class="vp-sc"><div class="vp-sc-lbl">Competitividad</div><div class="vp-sc-val" id="vpScComp" style="color:#F59E0B">-</div><div class="bar"><div class="bar-f" id="vpBComp" style="background:#F59E0B;width:0%"></div></div></div>
  </div>
  <!-- Desglose de puntos -->
  <div class="vp-desglose" id="vpDesglose" style="display:none">
    <div class="vp-desglose-title">Desglose de puntos SkillSelect</div>
    <table>
      <thead><tr><th>Factor</th><th>Detalle</th><th>Pts</th></tr></thead>
      <tbody id="vpDesgloseBody"></tbody>
      <tfoot><tr><td colspan="2">Subtotal (sin nominaci&oacute;n)</td><td id="vpSubtotalPts">-</td></tr></tfoot>
    </table>
    <div class="vp-desglose-nota" id="vpNotaNom"></div>
  </div>
  <div class="vp-div"></div>
  <!-- Secciones del informe -->
  <div class="vp-sec"><div class="vp-sec-h"><div class="vp-sec-num">1</div>Naturaleza y alcance del informe</div><div class="vp-sec-body" id="vpRAlcance"></div></div>
  <div class="vp-sec"><div class="vp-sec-h"><div class="vp-sec-num">2</div>An&aacute;lisis acad&eacute;mico</div><div class="vp-sec-body" id="vpRAcad"></div></div>
  <div class="vp-sec"><div class="vp-sec-h"><div class="vp-sec-num">3</div>An&aacute;lisis laboral</div><div class="vp-sec-body" id="vpRLaboral"></div></div>
  <div class="vp-sec"><div class="vp-sec-h"><div class="vp-sec-num">4</div>Marco regulatorio y ocupacional</div><div class="vp-anzsco" id="vpRAnzsco"></div></div>
  <div class="vp-sec"><div class="vp-sec-h"><div class="vp-sec-num">5</div>Variables de competitividad</div><div id="vpRVars"></div></div>
  <div class="vp-sec"><div class="vp-sec-h">Visas potenciales identificadas</div><div class="vp-tags" id="vpRVisas"></div></div>
  <div class="vp-sec" id="vpRRecomSec"><div class="vp-sec-h"><div class="vp-sec-num">6</div>Recomendaciones</div><div id="vpRRecom"></div></div>
  <div class="vp-div"></div>
  <!-- CTA -->
  <div class="vp-cta">
    <h3>&#x1F4C5; Valida tu resultado con un experto</h3>
    <p>Nuestro an&aacute;lisis de IA es orientativo. Un asesor migratorio registrado (MARA 0101111) revisar&aacute; tu perfil en detalle y te confirmar&aacute; si efectivamente puedes avanzar.<br><strong>Esta asesor&iacute;a es GRATUITA y sin compromiso.</strong></p>
    <div class="vp-cta-btns">
      <button class="vp-btn-cta" onclick="vpScrollToCalendar()">&#x1F4C5; Agendar asesor&iacute;a gratuita &rarr;</button>
      <button class="vp-btn-sec" onclick="vpGeneratePDF()">&#x1F4C4; Descargar informe PDF</button>
    </div>
  </div>
  <!-- Calendario GHL -->
  <div class="vp-cal-wrap" id="vpCalWrap">
    <iframe id="vpCalIframe" src="https://crm.vivaaustralia.com.au/widget/booking/jIZFnPViS594ZfTzVfHS" scrolling="yes" allowtransparency="true"></iframe>
  </div>
  <div class="vp-legal">Informe preliminar de car&aacute;cter orientativo. No constituye asesoramiento migratorio formal bajo la Migration Act 1958 (Cth). Viva Australia Internacional &middot; Frank Cross, Senior Migration Agent &middot; MARA 0101111</div>
  <button class="vp-btn-reset" onclick="vpReset()">&#x2190; Analizar otro perfil</button>
</div>

<!-- ═══ RESULTADO: NO-APTO ═══ -->
<div class="vp-card" data-screen="s-noapto" style="display:none">
  <div class="vp-disclaimer">&#x26A0;&#xFE0F; Este es un an&aacute;lisis orientativo generado por inteligencia artificial que puede contener errores. Tu situaci&oacute;n real podr&iacute;a ser diferente. Si tienes dudas, te recomendamos hablar con un asesor especializado.</div>
  <div class="vp-verdict noapto">
    <div class="vp-v-ico">&#x1F4A1;</div>
    <div><div class="vp-v-name" id="vpNAName">Tu perfil tiene oportunidades de mejora</div>
    <div class="vp-v-tag">Encontramos algunos aspectos que vale la pena trabajar</div></div>
  </div>
  <div class="vp-noapt-score">
    <div class="vp-noapt-sc"><div class="vp-noapt-sc-lbl">Puntaje estimado</div><div class="vp-noapt-sc-val" id="vpNAPts">-</div></div>
    <div class="vp-noapt-sc"><div class="vp-noapt-sc-lbl">Viabilidad</div><div class="vp-noapt-sc-val" id="vpNAVia">-</div></div>
    <div class="vp-noapt-sc"><div class="vp-noapt-sc-lbl">Puntaje m&iacute;nimo</div><div class="vp-noapt-sc-val" style="color:#7A8EA8">65 pts</div></div>
  </div>
  <!-- Desglose puntos no-apto -->
  <div class="vp-desglose" id="vpNADesglose" style="display:none">
    <div class="vp-desglose-title">Desglose de puntos SkillSelect</div>
    <table><thead><tr><th>Factor</th><th>Detalle</th><th>Pts</th></tr></thead>
    <tbody id="vpNADesgloseBody"></tbody>
    <tfoot><tr><td colspan="2">Subtotal</td><td id="vpNASubtotalPts">-</td></tr></tfoot></table>
    <div class="vp-desglose-nota" id="vpNANotaNom"></div>
  </div>
  <div class="vp-div"></div>
  <div class="vp-sec-h" style="margin-bottom:14px"><div class="vp-sec-num" style="background:rgba(245,158,11,.1);border-color:rgba(245,158,11,.3);color:#F59E0B">&#x26A1;</div>Aspectos a fortalecer</div>
  <div id="vpNABloqueantes"></div>
  <div class="vp-sec-h" style="margin:16px 0 14px"><span style="margin-right:8px">&#x1F4A1;</span>Plan de mejora sugerido</div>
  <div id="vpNARecom"></div>
  <div class="vp-cta">
    <h3>&#x1F4AC; &iquest;Tienes dudas? Habla con un asesor</h3>
    <p id="vpNACTAtxt">La IA puede equivocarse. Si crees que tu perfil tiene m&aacute;s potencial del que muestra este an&aacute;lisis, habla directamente con nosotros. Muchos perfiles que hoy necesitan ajustes pueden estar listos en 12&ndash;24 meses.</p>
    <div class="vp-cta-btns">
      <a class="vp-btn-wa" id="vpWALink" href="#" target="_blank">&#x1F4AC; Hablar con un asesor por WhatsApp &rarr;</a>
      <button class="vp-btn-sec" onclick="vpGeneratePDF()">&#x1F4C4; Descargar mi an&aacute;lisis</button>
    </div>
  </div>
  <div class="vp-legal">Informe preliminar de car&aacute;cter orientativo. Viva Australia Internacional &middot; MARA 0101111</div>
  <button class="vp-btn-reset" onclick="vpReset()">&#x2190; Analizar otro perfil</button>
</div>

<!-- ═══ CONFIRMACIÓN: CONTINUAR DESPUÉS ═══ -->
<div class="vp-card" data-screen="s-continuar" style="display:none">
  <div class="vp-confirm-ico">&#x2705;</div>
  <div class="vp-confirm-title">&#xA1;Listo, <span id="vpConfirmNombre"></span>!</div>
  <div class="vp-confirm-sub">Hemos guardado tu informaci&oacute;n. Te enviaremos un link por <strong>WhatsApp</strong> para que completes tu an&aacute;lisis cuando tengas tu CV disponible.<br><br>&#x1F4F1; Revisa tu WhatsApp en los pr&oacute;ximos minutos.</div>
  <div class="vp-confirm-tip">&#x1F4A1; Tip: Ten a la mano tu CV en formato PDF &mdash; es el que mejor funciona con nuestro sistema de an&aacute;lisis.</div>
</div>

</div><!-- .vp-wrap -->
</div><!-- #viva-preevm-app -->


<script>
(function(){
'use strict';

// ══════════════════════════════════════════════════════
// ESTADO GLOBAL
// ══════════════════════════════════════════════════════
var vpCfg = window._viva_preevm_cfg || {restUrl:'',nonce:'',continueToken:'',continueData:null};
var vpData = {};       // Datos recopilados del formulario
var vpContact = null;  // Datos del contacto en GHL
var vpResult = null;   // Resultado de la IA
var vpResultUrl = null;// URL del CPT resultado
var vpCvFile = null;   // Archivo CV
var vpCvBase64 = null; // CV en base64 (solo datos, sin prefijo data:...)
var vpCvMime = null;   // MIME del CV
var vpModoCV = 'cv';   // 'cv' o 'manual'
var vpQuizIdx = 0;     // Índice actual en QUIZ_QUESTIONS
var vpQuizHistory = [];// Historial para navegar atrás

// ══════════════════════════════════════════════════════
// QUIZ — DEFINICIÓN DE PREGUNTAS
// ══════════════════════════════════════════════════════
var QUIZ_QUESTIONS = [
  {id:'conexionAU', label:'🦘 ¿Has estado en Australia?', type:'pills', always:true,
   opts:[{v:'nunca',l:'🌏 Nunca he ido'},{v:'visita',l:'✈️ Sí, de visita'},{v:'estudio',l:'🎓 Estudié allá'},{v:'trabajo',l:'💼 Trabajé allá'}]},
  {id:'estadoCivil', label:'💍 ¿Cómo es tu situación sentimental?', type:'pills', always:true,
   opts:[{v:'soltero',l:'Soltero/a'},{v:'pareja',l:'En pareja'},{v:'casado',l:'Casado/a'}]},
  {id:'parejaProf', label:'🎓 ¿Tu pareja tiene título universitario?', type:'pills',
   cond:function(){return vpData.estadoCivil==='pareja'||vpData.estadoCivil==='casado';},
   opts:[{v:'ejerce',l:'Sí, y trabaja en su profesión'},{v:'no_ejerce',l:'Sí, pero no ejerce'},{v:'sin_titulo',l:'No tiene título'}]},
  {id:'parejaIngles', label:'🗣️ ¿Tu pareja habla inglés?', type:'pills',
   cond:function(){return vpData.parejaProf==='ejerce';},
   opts:[{v:'cert',l:'Sí, tiene certificación'},{v:'bien',l:'Sí, buen nivel sin certificar'},{v:'no',l:'No / muy básico'}]},
  {id:'certTipo', label:'📝 ¿Tienes certificación de inglés vigente?', type:'pills', always:true,
   opts:[{v:'IELTS Academic',l:'IELTS Academic'},{v:'PTE Academic',l:'PTE Academic'},{v:'TOEFL iBT',l:'TOEFL iBT'},{v:'Cambridge C1/C2',l:'Cambridge C1/C2'},{v:'Otro',l:'Otro'},{v:'ninguna',l:'No tengo'}]},
  {id:'certPuntaje', label:'📊 ¿Cuál fue tu puntaje overall?', type:'number', placeholder:'Ej: 7.0 para IELTS, 65 para PTE',
   cond:function(){return vpData.certTipo&&vpData.certTipo!=='ninguna';}},
  {id:'plazo', label:'🗓️ ¿Cuándo te gustaría hacer el salto?', type:'pills', always:true,
   opts:[{v:'ya',l:'🚀 Ya! Lo antes posible'},{v:'1-2a',l:'📅 En 1-2 años'},{v:'explorando',l:'🔍 Explorando'},{v:'no_se',l:'🤷 No sé aún'}]},
  {id:'inversion', label:'💰 Un proceso completo cuesta aprox. USD $10,000–$12,000. ¿Cómo te suena?', type:'pills', always:true,
   opts:[{v:'si',l:'✅ Tengo los recursos'},{v:'pronto',l:'⏳ Los junto en unos meses'},{v:'planificar',l:'😅 Necesito planificarlo'},{v:'no_decir',l:'Prefiero no decir'}]},
  {id:'decision', label:'🔥 Del 1 al 5, ¿qué tan decidido/a estás?', type:'scale', always:true,
   lblMin:'Solo curiosidad', lblMax:'¡All in! 🦘'},
  // BLOQUE B — si estudió o trabajó en AU
  {id:'expAU', label:'💼 ¿Trabajaste en tu profesión en Australia?', type:'pills',
   cond:function(){return vpData.conexionAU==='estudio'||vpData.conexionAU==='trabajo';},
   opts:[{v:'menos1',l:'Sí, menos de 1 año'},{v:'1-2',l:'1-2 años'},{v:'3-4',l:'3-4 años'},{v:'5mas',l:'5+ años'},{v:'no',l:'No en mi profesión'}]},
  {id:'estudioRegional', label:'📍 ¿Estudiaste o trabajaste en una zona regional?\n(Cualquier ciudad que NO sea Sydney, Melbourne o Brisbane)', type:'pills',
   cond:function(){return vpData.conexionAU==='estudio'||vpData.conexionAU==='trabajo';},
   opts:[{v:'si',l:'Sí'},{v:'no',l:'No'},{v:'no_se',l:'No sé'}]},
  // BLOQUE C — si nunca fue o de visita
  {id:'expExtranjero', label:'🌏 ¿Has trabajado en tu profesión en otro país además del tuyo?', type:'pills',
   cond:function(){return vpData.conexionAU==='nunca'||vpData.conexionAU==='visita';},
   opts:[{v:'si',l:'Sí'},{v:'no',l:'No'}]}
];

// ══════════════════════════════════════════════════════
// INIT
// ══════════════════════════════════════════════════════
function vpInit(){
  // Detectar token de continuación
  if(vpCfg.continueData){
    var d = vpCfg.continueData;
    // Precargar datos
    vpData = JSON.parse(JSON.stringify(d));
    // Mostrar paso 4 directamente
    vpGoScreen('s4');
    document.getElementById('vpSteps').style.display='flex';
    vpUpdateSteps(4);
    document.getElementById('vpS4Title').textContent='👋 ¡Hola '+( d.nombre||'') + '! Solo falta tu CV';
    document.getElementById('vpS4Sub').textContent='Tienes todo listo. Solo sube tu CV para completar el análisis y recibir tu informe.';
    return;
  }
  vpGoScreen('s1');
  // Setup drag & drop
  vpSetupDrop();
}

// ══════════════════════════════════════════════════════
// NAVEGACIÓN
// ══════════════════════════════════════════════════════
function vpGoScreen(id){
  document.querySelectorAll('#viva-preevm-app [data-screen]').forEach(function(el){
    el.style.display='none';
  });
  var el = document.querySelector('#viva-preevm-app [data-screen="'+id+'"]');
  if(el) el.style.display='block';
}

function vpUpdateSteps(step){
  for(var i=1;i<=5;i++){
    var sn = document.getElementById('vpSN'+i);
    var sl = document.getElementById('vpSL'+i);
    if(!sn) continue;
    sn.className = 'vp-sn';
    sl.className = 'vp-sl';
    if(i<step){sn.className+=' ok';sn.textContent='✓';}
    else if(i===step){sn.className+=' on';sl.className+=' on';sn.textContent=i;}
    else{sn.textContent=i;}
  }
}

// ══════════════════════════════════════════════════════
// PASO 1: EMAIL
// ══════════════════════════════════════════════════════
function vpHandleStep1(){
  var email = document.getElementById('vpEmail').value.trim();
  if(!email||!email.includes('@')){alert('Por favor ingresa un email válido.');return;}
  vpData.email = email;
  document.querySelector('[data-screen="s1"] button').disabled=true;
  document.querySelector('[data-screen="s1"] button').textContent='Buscando...';

  vpApi('ghl-lookup',{email:email}).then(function(res){
    if(res.found){
      vpContact = res;
      vpData.nombre    = res.firstName||'';
      vpData.apellido  = res.lastName||'';
      vpData.whatsapp  = res.phone||'';
      vpData.contactId = res.contactId;
      // Mostrar greeting y precargar campos
      document.getElementById('vpGHLGreeting').classList.add('show');
      document.getElementById('vpGHLFirstName').textContent = res.firstName;
      var n=document.getElementById('vpNombre'),a=document.getElementById('vpApellido'),w=document.getElementById('vpWA');
      if(res.firstName){n.value=res.firstName;n.readOnly=true;document.getElementById('vpNombreBadge').style.display='block';}
      if(res.lastName){a.value=res.lastName;a.readOnly=true;document.getElementById('vpApellidoBadge').style.display='block';}
      if(res.phone){w.value=res.phone;}
    }
    vpGoScreen('s2');
    vpUpdateSteps(2);
  }).catch(function(){
    // Si falla el lookup, continuar igual
    vpGoScreen('s2');
    vpUpdateSteps(2);
  }).finally(function(){
    var btn = document.querySelector('[data-screen="s1"] button');
    if(btn){btn.disabled=false;btn.textContent='Continuar →';}
  });
}

// ══════════════════════════════════════════════════════
// PASO 2: DATOS DEL PERFIL
// ══════════════════════════════════════════════════════
function vpHandleStep2(){
  var nombre   = document.getElementById('vpNombre').value.trim();
  var apellido = document.getElementById('vpApellido').value.trim();
  var pais     = document.getElementById('vpPais').value;
  var prof     = document.getElementById('vpProfesion').value.trim();
  var edad     = document.getElementById('vpEdad').value;
  var ingles   = document.getElementById('vpIngles').value;
  var exp      = document.getElementById('vpExp').value;
  if(!nombre||!apellido||!pais||!prof||!edad||!ingles||!exp){
    alert('Por favor completa todos los campos.');return;
  }
  vpData.nombre     = nombre;
  vpData.apellido   = apellido;
  vpData.whatsapp   = document.getElementById('vpWA').value.trim();
  vpData.pais       = pais;
  vpData.profesion  = prof;
  vpData.edad       = edad;
  vpData.ingles     = ingles;
  vpData.experiencia= exp;
  vpGoScreen('s3');
  vpUpdateSteps(3);
  vpQuizIdx     = 0;
  vpQuizHistory = [];
  vpRenderQuizQ(vpGetNextQuizIdx(-1));
}

// ══════════════════════════════════════════════════════
// PASO 3: QUIZ
// ══════════════════════════════════════════════════════
function vpGetNextQuizIdx(from){
  for(var i=from+1;i<QUIZ_QUESTIONS.length;i++){
    var q=QUIZ_QUESTIONS[i];
    if(q.always||(q.cond&&q.cond())) return i;
  }
  return -1;
}

function vpGetPrevQuizIdx(from){
  for(var i=from-1;i>=0;i--){
    var q=QUIZ_QUESTIONS[i];
    if(q.always||(q.cond&&q.cond())) return i;
  }
  return -1;
}

function vpRenderQuizQ(idx){
  if(idx<0){
    // Quiz terminado → Paso 4
    vpGoScreen('s4');
    vpUpdateSteps(4);
    return;
  }
  vpQuizIdx = idx;
  var q = QUIZ_QUESTIONS[idx];
  var total = 0; for(var i=0;i<QUIZ_QUESTIONS.length;i++){var qq=QUIZ_QUESTIONS[i];if(qq.always||(qq.cond&&qq.cond()))total++;}
  var done  = 0; for(var j=0;j<QUIZ_QUESTIONS.length;j++){var qj=QUIZ_QUESTIONS[j];if(j<idx&&(qj.always||(qj.cond&&qj.cond())))done++;}
  var pct = total>0?Math.round((done/total)*100):0;
  document.getElementById('vpQpBar').style.width=pct+'%';
  document.getElementById('vpQuizPrevBtn').style.display=(done>0||vpQuizHistory.length>0)?'block':'none';

  var html='<div class="vp-quiz-wrap">';
  html+='<div class="vp-quiz-q">'+q.label.replace(/\n/g,'<br>')+'</div>';

  if(q.type==='pills'){
    html+='<div class="vp-pills" id="vpQPills">';
    (q.opts||[]).forEach(function(o){
      var sel=vpData[q.id]===o.v?' selected':'';
      html+='<button class="vp-pill'+sel+'" onclick="vpSelectPill(this,\''+q.id+'\',\''+o.v+'\',\'vpQPills\')">'+o.l+'</button>';
    });
    html+='</div>';
  } else if(q.type==='number'){
    var curval=vpData[q.id]||'';
    html+='<div class="vp-row one"><div class="vp-fld"><input type="text" id="vpQNumInput" value="'+curval+'" placeholder="'+(q.placeholder||'')+'" oninput="vpData[\''+q.id+'\']=this.value"></div></div>';
  } else if(q.type==='scale'){
    var curval2=vpData[q.id]||0;
    html+='<div class="vp-scale" id="vpQScale">';
    for(var k=1;k<=5;k++){
      var sel2=curval2==k?' selected':'';
      html+='<button class="vp-scale-btn'+sel2+'" onclick="vpSelectScale('+k+')" data-v="'+k+'">'+k+'</button>';
    }
    html+='</div><div class="vp-scale-labels"><span>'+( q.lblMin||'')+'</span><span>'+(q.lblMax||'')+'</span></div>';
  }
  html+='</div>';
  document.getElementById('vpQuizContainer').innerHTML=html;
  var nb=document.getElementById('vpQuizNextBtn');
  // Si es la última pregunta activa, cambiar texto del botón
  var nextIdx=vpGetNextQuizIdx(idx);
  nb.textContent=nextIdx<0?'Siguiente: Tu CV 📄 →':'Siguiente →';
}

function vpSelectPill(btn,field,val,containerID){
  vpData[field]=val;
  var container=document.getElementById(containerID);
  if(container){container.querySelectorAll('.vp-pill').forEach(function(b){b.classList.remove('selected');});}
  btn.classList.add('selected');
  // Auto-advance para pills (excepto la última pregunta)
  setTimeout(function(){
    var nextIdx=vpGetNextQuizIdx(vpQuizIdx);
    if(nextIdx<0){
      // Última pregunta
    } else {
      vpQuizHistory.push(vpQuizIdx);
      vpRenderQuizQ(nextIdx);
    }
  },220);
}

function vpSelectScale(v){
  vpData.decision=v;
  document.querySelectorAll('#vpQScale .vp-scale-btn').forEach(function(b){b.classList.remove('selected');});
  document.querySelector('#vpQScale [data-v="'+v+'"]').classList.add('selected');
}

function vpQuizNext(){
  var q=QUIZ_QUESTIONS[vpQuizIdx];
  // Validar que hay una respuesta
  if(q.type==='pills'&&!vpData[q.id]){alert('Por favor selecciona una opción.');return;}
  if(q.type==='scale'&&!vpData[q.id]){alert('Por favor selecciona tu nivel de decisión (1-5).');return;}
  var nextIdx=vpGetNextQuizIdx(vpQuizIdx);
  if(nextIdx>=0){
    vpQuizHistory.push(vpQuizIdx);
    vpRenderQuizQ(nextIdx);
  } else {
    vpGoScreen('s4');
    vpUpdateSteps(4);
  }
}

function vpQuizPrev(){
  if(vpQuizHistory.length>0){
    var prevIdx=vpQuizHistory.pop();
    vpRenderQuizQ(prevIdx);
  } else {
    vpGoScreen('s2');vpUpdateSteps(2);
  }
}

// ══════════════════════════════════════════════════════
// PASO 4: CV
// ══════════════════════════════════════════════════════
function vpSetupDrop(){
  var drop=document.getElementById('vpDrop');
  var inp=document.getElementById('vpCvInput');
  if(!drop||!inp) return;
  drop.addEventListener('dragover',function(e){e.preventDefault();drop.classList.add('over');});
  drop.addEventListener('dragleave',function(){drop.classList.remove('over');});
  drop.addEventListener('drop',function(e){
    e.preventDefault();drop.classList.remove('over');
    var files=e.dataTransfer.files;
    if(files[0]) vpProcessCv(files[0]);
  });
  inp.addEventListener('change',function(){
    if(inp.files[0]) vpProcessCv(inp.files[0]);
  });
}

function vpProcessCv(file){
  var maxMB=10;
  if(file.size>maxMB*1024*1024){alert('El archivo es demasiado grande. Máximo '+maxMB+' MB.');return;}
  vpCvFile=file;
  vpCvMime=file.type||'application/octet-stream';
  var drop=document.getElementById('vpDrop');
  drop.classList.add('ok');
  document.getElementById('vpDropIco').textContent='✅';
  document.getElementById('vpDropTitle').textContent=file.name;
  document.getElementById('vpDropSub').textContent='Archivo listo · '+Math.round(file.size/1024)+' KB';

  var reader=new FileReader();
  if(vpCvMime==='application/pdf'){
    reader.readAsDataURL(file);
    reader.onload=function(e){
      // e.target.result = "data:application/pdf;base64,XXXXX"
      vpCvBase64=e.target.result.split(',')[1];
    };
  } else {
    reader.readAsText(file,'utf-8');
    reader.onload=function(e){
      // Para doc/txt: enviamos texto como base64
      vpCvBase64=btoa(unescape(encodeURIComponent(e.target.result)));
      vpCvMime='text/plain';
    };
  }
}

function vpHandleAnalyze(){
  // Determinar modo CV
  vpModoCV = vpCvFile?'cv':'manual';
  if(vpModoCV==='manual'){
    // Verificar campos manuales si están visibles
    var manualFields=document.getElementById('vpManualFields');
    if(manualFields&&manualFields.style.display!=='none'){
      vpData.titulo    =document.getElementById('vpTitulo').value.trim();
      vpData.postgrado =document.getElementById('vpPostgrado').value.trim();
      vpData.empresa   =document.getElementById('vpEmpresa').value.trim();
      vpData.cargo     =document.getElementById('vpCargo').value.trim();
      vpData.descripcion=document.getElementById('vpDesc').value.trim();
      if(!vpData.titulo){alert('Por favor ingresa tu título universitario.');return;}
    }
  }
  // ¿Tiene conexión con Australia? → Paso 4b
  var conAU=vpData.conexionAU;
  var hasAU=(conAU==='estudio'||conAU==='trabajo');
  if(hasAU&&vpCvFile){
    vpGoScreen('s4b');
  } else {
    vpLaunchAnalysis();
  }
}

function vpShowManualFields(){
  var mf=document.getElementById('vpManualFields');
  if(mf){mf.style.display='block';mf.scrollIntoView({behavior:'smooth',block:'start'});}
  vpModoCV='manual';
}

// ══════════════════════════════════════════════════════
// CONTINUAR DESPUÉS
// ══════════════════════════════════════════════════════
function vpContinueLater(){
  var draftData={
    nombre:vpData.nombre||'', apellido:vpData.apellido||'', email:vpData.email||'',
    whatsapp:vpData.whatsapp||'', pais:vpData.pais||'', profesion:vpData.profesion||'',
    edad:vpData.edad||'', ingles:vpData.ingles||'', experiencia:vpData.experiencia||'',
    conexionAU:vpData.conexionAU||'', estadoCivil:vpData.estadoCivil||'',
    parejaProf:vpData.parejaProf||'', parejaIngles:vpData.parejaIngles||'',
    certTipo:vpData.certTipo||'', certPuntaje:vpData.certPuntaje||'',
    plazo:vpData.plazo||'', inversion:vpData.inversion||'', decision:vpData.decision||'',
    expAU:vpData.expAU||'', estudioRegional:vpData.estudioRegional||''
  };

  // GHL upsert + save-draft en paralelo; actualizamos continue_link cuando AMBOS terminan
  var p_ghl_draft = vpApi('ghl-upsert',{
    email:vpData.email, firstName:vpData.nombre, lastName:vpData.apellido,
    phone:vpData.whatsapp, country:vpData.pais,
    customFields:[
      {key:'preevm_score',     field_value:'0'},
      {key:'preevm_viability', field_value:'pendiente'},
      {key:'preevm_decision',  field_value:String(vpData.decision||0)}
    ]
  });
  var p_draft = vpApi('save-draft', draftData, true);

  Promise.all([p_ghl_draft, p_draft]).then(function(results){
    var ghlRes   = results[0];
    var draftRes = results[1];
    var contactId = (ghlRes && ghlRes.contactId) ? ghlRes.contactId : (vpData.contactId||'');
    if(contactId) vpData.contactId = contactId;
    console.log('[VIVA GHL] vpContinueLater — contactId:', contactId, '| continueUrl:', draftRes&&draftRes.continueUrl);

    // Tags
    if(contactId) vpApi('ghl-tag',{contactId:contactId,tags:['test-preevm','cv-pendiente']});

    // Actualizar continue_link — no requiere contactId, basta el email para el upsert
    if(draftRes && draftRes.continueUrl){
      console.log('[VIVA GHL] Enviando preevm_continue_link →', draftRes.continueUrl);
      vpApi('ghl-upsert',{
        email:vpData.email,
        firstName:vpData.nombre, lastName:vpData.apellido,
        customFields:[{key:'preevm_continue_link',field_value:draftRes.continueUrl}]
      }).then(function(r){ console.log('[VIVA GHL] continue_link guardado, respuesta:', r); })
        .catch(function(e){ console.error('[VIVA GHL] Error guardando continue_link:', e); });
      // Nota en GHL
      var fecha=new Date().toLocaleString('es-CO',{timeZone:'America/Bogota'});
      var nota='📋 TEST PRE-EVM — CV Pendiente — '+fecha+'\n';
      nota+='━━━━━━━━━━━━━━━━━━━━━━━\n';
      nota+='Quiz completado: ✅\nCV adjunto: ❌ Pendiente\nAnálisis: ⏳ En espera del CV\n';
      nota+='━━━━━━━━━━━━━━━━━━━━━━━\n';
      nota+='Profesión: '+(vpData.profesion||'-')+' | Edad: '+(vpData.edad||'-')+'\n';
      nota+='Inglés: '+(vpData.ingles||'-')+' | Experiencia: '+(vpData.experiencia||'-')+'\n';
      nota+='País: '+(vpData.pais||'-')+'\n';
      nota+='━━━━━━━━━━━━━━━━━━━━━━━\n';
      nota+='Intención: '+(vpData.decision||'-')+'/5 | Plazo: '+(vpData.plazo||'-')+'\n';
      nota+='Inversión: '+(vpData.inversion||'-')+'\n';
      nota+='━━━━━━━━━━━━━━━━━━━━━━━\n';
      nota+='🔗 Link para completar: '+draftRes.continueUrl+'\n';
      nota+='⚡ Tag cv-pendiente activado → workflow de seguimiento';
      vpApi('ghl-note',{contactId:contactId,body:nota});
    }
  }).catch(function(e){ console.warn('[VIVA GHL] Error en continuar después:', e); });

  // Mostrar confirmación
  document.getElementById('vpConfirmNombre').textContent=vpData.nombre||'';
  vpGoScreen('s-continuar');
}

// ══════════════════════════════════════════════════════
// ANÁLISIS
// ══════════════════════════════════════════════════════
function vpLaunchAnalysis(){
  // Recoger datos de paso 4b si aplica
  vpGoScreen('s-loading');
  vpUpdateSteps(5);
  vpAnimateLoading(function(){});
  vpDoAnalysis();
}

function vpAnimateLoading(onDone){
  var steps=['ls1','ls2','ls3','ls4','ls5','ls6'];
  var i=0;
  function next(){
    if(i>0) vpLsSet(steps[i-1],'done');
    if(i<steps.length){vpLsSet(steps[i],'active');i++;setTimeout(next,900);}
    else if(onDone) onDone();
  }
  next();
}

function vpLsSet(id,state){
  var el=document.getElementById(id);
  if(!el) return;
  el.className='ls '+(state==='done'?'done':'active');
  el.querySelector('.ls-ico').textContent=state==='done'?'✓':'◉';
}

function vpDoAnalysis(){
  var payload={
    nombre:vpData.nombre||'', apellido:vpData.apellido||'', email:vpData.email||'',
    whatsapp:vpData.whatsapp||'', pais:vpData.pais||'', profesion:vpData.profesion||'',
    edad:vpData.edad||'', ingles:vpData.ingles||'', experiencia:vpData.experiencia||'',
    estadoCivil:vpData.estadoCivil||'', parejaProf:vpData.parejaProf||'', parejaIngles:vpData.parejaIngles||'',
    certTipo:vpData.certTipo||'', certPuntaje:vpData.certPuntaje||'',
    conexionAU:vpData.conexionAU||'', expAU:vpData.expAU||'',
    estudioAU:vpData.conexionAU==='estudio'?'si':'no',
    estudioRegional:vpData.estudioRegional||'', profYear:vpData.profYear||'no',
    naati:vpData.naati||'no', plazo:vpData.plazo||'', inversion:vpData.inversion||'',
    decision:vpData.decision||'', modoCV:vpModoCV,
    titulo:vpData.titulo||'', postgrado:vpData.postgrado||'',
    empresa:vpData.empresa||'', cargo:vpData.cargo||'', descripcion:vpData.descripcion||'',
    cvBase64:vpCvBase64||'', cvMime:vpCvMime||''
  };

  vpApi('analyze',payload).then(function(result){
    vpResult=result;
    console.group('[VIVA PRE-EVM] 🔍 Resultado procesado por vpPostAnalysis');
    console.log('viability →', result.viability);
    console.log('pts →', result.pts, '(tipo:', typeof result.pts, ')');
    console.log('viaPct →', result.viaPct, '| compPct →', result.compPct);
    console.log('alcance →', (result.alcance||'').substring(0,80)+'…');
    console.log('academico →', (result.academico||'').substring(0,80)+'…');
    console.log('laboral →', (result.laboral||'').substring(0,80)+'…');
    console.log('desglosePuntos →', result.desglosePuntos);
    console.log('anzsco →', result.anzsco);
    console.log('_provider →', result._provider);
    console.groupEnd();
    vpPostAnalysis(result);
  }).catch(function(err){
    console.error('[VIVA PRE-EVM] ❌ Catch en analyze:', err);
    alert('Error al analizar: '+(err.message||'Error desconocido')+'. Por favor intenta de nuevo.');
    vpGoScreen('s4');vpUpdateSteps(4);
  });
}

function vpPostAnalysis(result){
  var viability=result.viability||'no-apto';
  var tags=['test-preevm'];
  if(viability==='apto')tags.push('preevm-califica');
  else if(viability==='parcial')tags.push('preevm-parcial');
  else tags.push('preevm-no-califica');
  var dec=parseInt(vpData.decision)||0;
  if(dec>=4)tags.push('lead-caliente');
  else if(dec===3)tags.push('lead-tibio');
  else if(dec<=2)tags.push('lead-frio');
  if(vpData.inversion==='si')tags.push('capacidad-inversion');

  // GHL upsert + save-result en paralelo; actualizamos result_link cuando AMBOS terminan
  var p_ghl_result = vpApi('ghl-upsert',{
    email:vpData.email, firstName:vpData.nombre, lastName:vpData.apellido,
    phone:vpData.whatsapp, country:vpData.pais,
    customFields:[
      {key:'preevm_score',     field_value:String(result.pts||0)},
      {key:'preevm_viability', field_value:viability},
      {key:'preevm_decision',  field_value:String(vpData.decision||0)}
    ]
  });
  var p_save_result = vpApi('save-result',{
    nombre:vpData.nombre, apellido:vpData.apellido, email:vpData.email,
    whatsapp:vpData.whatsapp, pais:vpData.pais, profesion:vpData.profesion,
    edad:vpData.edad, ingles:vpData.ingles, experiencia:vpData.experiencia,
    contactId:vpData.contactId||'', result:result
  },true);

  Promise.all([p_ghl_result, p_save_result]).then(function(results){
    var ghlRes  = results[0];
    var saveRes = results[1];
    var contactId = (ghlRes && ghlRes.contactId) ? ghlRes.contactId : (vpData.contactId||'');
    if(contactId) vpData.contactId = contactId;
    console.log('[VIVA GHL] vpPostAnalysis — contactId:', contactId, '| resultUrl:', saveRes&&saveRes.resultUrl);

    if(saveRes && saveRes.resultUrl){
      vpResultUrl = saveRes.resultUrl;
      result.resultUrl = saveRes.resultUrl;
      // Actualizar result_link — no requiere contactId, basta el email para el upsert
      console.log('[VIVA GHL] Enviando preevm_result_link →', saveRes.resultUrl);
      vpApi('ghl-upsert',{
        email:vpData.email, firstName:vpData.nombre, lastName:vpData.apellido,
        customFields:[{key:'preevm_result_link',field_value:saveRes.resultUrl}]
      }).then(function(r){ console.log('[VIVA GHL] result_link guardado, respuesta:', r); })
        .catch(function(e){ console.error('[VIVA GHL] Error guardando result_link:', e); });
    }
    // Tags
    if(contactId) vpApi('ghl-tag',{contactId:contactId,tags:tags});
    // Nota GHL
    if(contactId){
      var az=(result.anzsco||[])[0]||{};
      var visaStr=(result.visas||[]).join(', ');
      var viLabel=viability==='apto'?'Indicadores positivos':viability==='parcial'?'Parcial':'Áreas de mejora';
      var fecha=new Date().toLocaleString('es-CO',{timeZone:'America/Bogota'});
      var nota='📊 RESULTADO PRE-EVM — '+fecha+'\n';
      nota+='━━━━━━━━━━━━━━━━━━━━━━━\n';
      nota+='Puntaje estimado: '+(result.pts||0)+'/120 pts\n';
      nota+='Viabilidad: '+(result.viaPct||0)+'%\n';
      nota+='Competitividad: '+(result.compPct||0)+'%\n';
      nota+='Resultado: '+viLabel+'\n';
      nota+='Visas identificadas: '+visaStr+'\n';
      nota+='━━━━━━━━━━━━━━━━━━━━━━━\n';
      nota+='Profesión: '+(vpData.profesion||'-')+'\n';
      if(az.code) nota+='ANZSCO: '+az.code+' — '+az.name+'\n';
      nota+='━━━━━━━━━━━━━━━━━━━━━━━\n';
      nota+='Intención: '+(vpData.decision||'-')+'/5 | Plazo: '+(vpData.plazo||'-')+'\n';
      nota+='Inversión: '+(vpData.inversion||'-')+'\n';
      nota+='━━━━━━━━━━━━━━━━━━━━━━━\n';
      if(saveRes && saveRes.resultUrl) nota+='🔗 Ver informe completo: '+saveRes.resultUrl+'\n';
      vpApi('ghl-note',{contactId:contactId,body:nota});
    }

    // Mostrar resultado
    if(viability==='no-apto'){vpShowNoApto(result);}
    else{vpShowResult(result);}
  }).catch(function(){
    // Si falla, mostrar resultado igual
    if(viability==='no-apto'){vpShowNoApto(result);}
    else{vpShowResult(result);}
  });
}

// ══════════════════════════════════════════════════════
// MOSTRAR RESULTADO APTO / PARCIAL
// ══════════════════════════════════════════════════════
var ICONS={cake:'🎂',speech:'🗣️',briefcase:'💼',clipboard:'📋',grad:'🎓',target:'🎯',star:'⭐',check:'✅',warning:'⚠️',book:'📚',chart:'📊',pin:'📍',rocket:'🚀',key:'🔑',time:'⏰',X:'⚡',x:'⚡'};

function vpShowResult(d){
  vpGoScreen('s-result');
  var vb=document.getElementById('vpVerdictBar');
  var nom=d.nom||'';
  if(d.viability==='apto'){
    vb.className='vp-verdict apto';
    document.getElementById('vpVIco').textContent='🟢';
    document.getElementById('vpVName').textContent='🦘 ¡'+nom+', buenas noticias!';
    document.getElementById('vpVTag').textContent='Según nuestro análisis preliminar de IA, tu perfil muestra indicadores positivos para un proceso de General Skilled Migration a Australia.';
  } else {
    vb.className='vp-verdict parcial';
    document.getElementById('vpVIco').textContent='🟡';
    document.getElementById('vpVName').textContent=nom+', perfil con potencial';
    document.getElementById('vpVTag').textContent='Según nuestro análisis preliminar, tu perfil cumple los mínimos pero hay variables que podrían trabajarse para ser más competitivo.';
  }

  document.getElementById('vpScPts').textContent=(d.pts||0)+' pts';
  document.getElementById('vpScVia').textContent=(d.viaPct||0)+'%';
  document.getElementById('vpScComp').textContent=(d.compPct||0)+'%';
  setTimeout(function(){
    document.getElementById('vpBPts').style.width=Math.min(((d.pts||0)/120)*100,100)+'%';
    document.getElementById('vpBVia').style.width=(d.viaPct||0)+'%';
    document.getElementById('vpBComp').style.width=(d.compPct||0)+'%';
  },300);

  // Desglose de puntos
  vpRenderDesglose(d,'vpDesgloseBody','vpSubtotalPts','vpNotaNom','vpDesglose');

  document.getElementById('vpRAlcance').textContent=d.alcance||'';
  document.getElementById('vpRAcad').textContent=d.academico||'';
  document.getElementById('vpRLaboral').textContent=d.laboral||'';

  var az=document.getElementById('vpRAnzsco'); az.innerHTML='';
  (d.anzsco||[]).forEach(function(a){
    az.innerHTML+='<div class="vp-az"><span class="vp-az-code">'+a.code+'</span><div><div class="vp-az-name">'+a.name+'</div><div class="vp-az-note">'+(a.note||'')+'</div></div></div>';
  });
  // Shortage map por ocupación
  vpRenderShortageMap(d,'vpRAnzsco');

  var vv=document.getElementById('vpRVars'); vv.innerHTML='';
  (d.variables||[]).forEach(function(v){
    var ico=ICONS[v.icon]||v.icon||'•';
    vv.innerHTML+='<div class="vp-vari"><div class="vp-vari-ico">'+ico+'</div><div><div class="vp-vari-t">'+v.title+'</div><div class="vp-vari-d">'+v.desc+'</div></div></div>';
  });

  var vs=document.getElementById('vpRVisas'); vs.innerHTML='';
  (d.visas||[]).forEach(function(v){vs.innerHTML+='<span class="vp-tag v">'+v+'</span>';});

  var rr=document.getElementById('vpRRecom'); rr.innerHTML='';
  (d.recomendaciones||[]).forEach(function(r){
    rr.innerHTML+='<div class="vp-recom-item"><span>'+(ICONS[r.icon]||r.icon||'🎯')+'</span><div>'+r.texto+'</div></div>';
  });
  if(!(d.recomendaciones||[]).length) document.getElementById('vpRRecomSec').style.display='none';

  // URL del resultado online
  if(vpResultUrl){
    document.getElementById('vpResultUrlBanner').style.display='flex';
    document.getElementById('vpResultUrlLink').href=vpResultUrl;
    document.getElementById('vpResultUrlLink').textContent=vpResultUrl;
  }
  document.querySelector('[data-screen="s-result"]').scrollIntoView({behavior:'smooth',block:'start'});
}

// ══════════════════════════════════════════════════════
// MOSTRAR RESULTADO NO-APTO
// ══════════════════════════════════════════════════════
function vpShowNoApto(d){
  vpGoScreen('s-noapto');
  var nom=d.nom||'';
  document.getElementById('vpNAName').textContent='💡 '+nom+', tu perfil tiene oportunidades de mejora';
  document.getElementById('vpNAPts').textContent=(d.pts||0)+' pts';
  document.getElementById('vpNAVia').textContent=(d.viaPct||0)+'%';

  // Desglose
  vpRenderDesglose(d,'vpNADesgloseBody','vpNASubtotalPts','vpNANotaNom','vpNADesglose');

  var bEl=document.getElementById('vpNABloqueantes'); bEl.innerHTML='';
  (d.bloqueantes||[]).forEach(function(b){
    var ico=b.icon==='X'?'⚡':b.icon||'⚡';
    bEl.innerHTML+='<div class="vp-blocker"><div class="vp-blocker-ico">'+ico+'</div><div><div class="vp-blocker-t">'+(b.titulo||b.title||'')+'</div><div class="vp-blocker-d">'+(b.desc||'')+'</div></div></div>';
  });

  var rEl=document.getElementById('vpNARecom'); rEl.innerHTML='';
  (d.recomendaciones||[]).forEach(function(r){
    rEl.innerHTML+='<div class="vp-recom-item"><span>'+(r.icon||'💡')+'</span><div>'+r.texto+'</div></div>';
  });

  if(d.proximoPaso) document.getElementById('vpNACTAtxt').textContent=d.proximoPaso;

  // WhatsApp link personalizado
  var waMsg=encodeURIComponent('Hola, acabo de hacer el test Pre-EVM y me gustaría que revisaran mi caso. Mi nombre es '+(d.nom||'')+' '+(d.ape||'')+' y trabajo como '+(d.prof||d.profesion||'')+'. Gracias.');
  document.getElementById('vpWALink').href='https://wa.me/5716015800581?text='+waMsg;

  document.querySelector('[data-screen="s-noapto"]').scrollIntoView({behavior:'smooth',block:'start'});
}

// ══════════════════════════════════════════════════════
// DESGLOSE DE PUNTOS
// ══════════════════════════════════════════════════════
function vpRenderDesglose(d,tbodyId,subtotalId,notaId,wrapId){
  var desglose=d.desglosePuntos;
  if(!desglose) return;
  var tbody=document.getElementById(tbodyId);
  if(!tbody) return;
  document.getElementById(wrapId).style.display='block';
  var rows=[
    ['Edad',desglose.edad],['Inglés',desglose.ingles],
    ['Experiencia offshore',desglose.experienciaOffshore],['Experiencia onshore',desglose.experienciaOnshore],
    ['Educación',desglose.educacion],['Estudio en Australia',desglose.estudioAustralia],
    ['Zona regional AU',desglose.estudioRegional],['Ed. especializada STEM',desglose.educacionEspecializada],
    ['Partner skills',desglose.partnerSkills],['Professional Year',desglose.professionalYear],
    ['NAATI',desglose.naati]
  ];
  var html='';
  rows.forEach(function(row){
    var label=row[0], item=row[1];
    if(!item) return;
    var pts=item.puntos||0;
    var cls=pts>0?'pts-pos':'pts-zero';
    html+='<tr><td>'+label+'</td><td style="color:#7A8EA8;font-size:12px">'+(item.detalle||'')+'</td><td class="'+cls+'">'+pts+'</td></tr>';
  });
  tbody.innerHTML=html;
  document.getElementById(subtotalId).textContent=(desglose.subtotal||d.pts||0)+' pts';
  if(desglose.notaNominacion) document.getElementById(notaId).textContent='📝 '+desglose.notaNominacion;
}

// ══════════════════════════════════════════════════════
// PDF
// ══════════════════════════════════════════════════════
var LOGO_PDF_B64 = '/9j/4AAQSkZJRgABAQAAAQABAAD/4gHYSUNDX1BST0ZJTEUAAQEAAAHIAAAAAAQwAABtbnRyUkdCIFhZWiAH4AABAAEAAAAAAABhY3NwAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAQAA9tYAAQAAAADTLQAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAlkZXNjAAAA8AAAACRyWFlaAAABFAAAABRnWFlaAAABKAAAABRiWFlaAAABPAAAABR3dHB0AAABUAAAABRyVFJDAAABZAAAAChnVFJDAAABZAAAAChiVFJDAAABZAAAAChjcHJ0AAABjAAAADxtbHVjAAAAAAAAAAEAAAAMZW5VUwAAAAgAAAAcAHMAUgBHAEJYWVogAAAAAAAAb6IAADj1AAADkFhZWiAAAAAAAABimQAAt4UAABjaWFlaIAAAAAAAACSgAAAPhAAAts9YWVogAAAAAAAA9tYAAQAAAADTLXBhcmEAAAAAAAQAAAACZmYAAPKnAAANWQAAE9AAAApbAAAAAAAAAABtbHVjAAAAAAAAAAEAAAAMZW5VUwAAACAAAAAcAEcAbwBvAGcAbABlACAASQBuAGMALgAgADIAMAAxADb/2wBDAAUDBAQEAwUEBAQFBQUGBwwIBwcHBw8LCwkMEQ8SEhEPERETFhwXExQaFRERGCEYGh0dHx8fExciJCIeJBweHx7/2wBDAQUFBQcGBw4ICA4eFBEUHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh7/wAARCAQ5BDkDASIAAhEBAxEB/8QAHQABAAICAwEBAAAAAAAAAAAAAAcIBQYDBAkCAf/EAFkQAAIBAwIDBQQDBw0OBgICAwABAgMEBQYRBxIhCBMxQWEiUXGBFIKRFSMyQnKhshYXGDY3UlZidJKxwdMkM0NVc3WEk5SipLO00jVTVKPC0TThRPBjlfH/xAAcAQEAAgIDAQAAAAAAAAAAAAAABgcEBQIDCAH/xABGEQACAQICBQoDBAgEBgMBAAAAAQIDBAURBiExQXESEyJRYYGRobHRFDLBB3Lh8BUWNDVCUlOyIzND8RckVGKS0iWCwqL/2gAMAwEAAhEDEQA/AKZAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA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AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAf/Z';

function vpGeneratePDF(){
  if(!vpResult||typeof window.jspdf==='undefined'){
    alert('El PDF estará disponible en un momento. Por favor intenta de nuevo.');return;
  }
  var jsPDF=window.jspdf.jsPDF;
  var doc=new jsPDF({unit:'mm',format:'a4'});
  var d=vpResult, W=210, M=20, TW=170;

  // Cover
  doc.setFillColor(7,17,31);doc.rect(0,0,W,52,'F');
  doc.setFillColor(232,96,10);doc.rect(0,52,W,3,'F');
  try{doc.addImage('data:image/png;base64,'+LOGO_PDF_B64,'PNG',M,12,55,28);}catch(e){}
  doc.setFont('helvetica','normal');doc.setFontSize(8.5);doc.setTextColor(122,142,168);
  doc.text('MARA 0101111 · Frank Cross, Senior Migration Agent',W-M,40,{align:'right'});

  doc.setFillColor(247,248,250);doc.rect(0,55,W,242,'F');
  doc.setFontSize(20);doc.setFont('helvetica','bold');doc.setTextColor(7,17,31);
  doc.text('INFORME PRELIMINAR DE',M,72);
  doc.text('EVALUACIÓN DE VIABILIDAD MIGRATORIA',M,82);

  // Badge — colores neutros
  var vc=d.viability==='apto'?[15,190,124]:d.viability==='parcial'?[245,158,11]:[180,180,100];
  doc.setFillColor.apply(doc,vc);doc.roundedRect(M,88,110,10,2,2,'F');
  doc.setFontSize(8.5);doc.setTextColor(255,255,255);
  var vl=d.viability==='apto'?'INDICADORES POSITIVOS':d.viability==='parcial'?'PERFIL CON POTENCIAL':'ÁREAS DE MEJORA IDENTIFICADAS';
  doc.text(vl,M+55,94.5,{align:'center'});

  doc.setFontSize(15);doc.setFont('helvetica','bold');doc.setTextColor(7,17,31);
  doc.text((d.nom||'')+' '+(d.ape||''),M,110);
  doc.setFontSize(10);doc.setFont('helvetica','normal');doc.setTextColor(100,115,130);
  doc.text((d.tagline||'')+(d.prof?' · '+d.prof:''),M,117);
  doc.setFontSize(8.5);doc.setTextColor(80,90,105);
  doc.text('País: '+(d.pais||'—'),M,126);
  doc.text('Edad: '+(d.edad||'—'),M+50,126);
  doc.text('Inglés: '+(d.eng||'—'),M+100,126);

  // Disclaimer en portada
  doc.setFontSize(7.5);doc.setTextColor(130,140,155);
  var disc='IMPORTANTE: Este informe fue generado por inteligencia artificial y tiene carácter 100% orientativo. Puede contener imprecisiones. Solo un agente migratorio registrado (MARA) puede confirmar la elegibilidad real.';
  var discLines=doc.splitTextToSize(disc,TW);
  doc.text(discLines,M,132);

  // Scores
  doc.setFillColor(237,240,245);doc.rect(M,145,TW,24,'F');
  doc.setFontSize(7.5);doc.setTextColor(100,115,130);
  doc.text('PUNTAJE ESTIMADO',M+5,152);
  doc.text('VIABILIDAD',M+TW/3+5,152);
  doc.text('COMPETITIVIDAD',M+2*TW/3+5,152);
  doc.setFontSize(16);doc.setFont('helvetica','bold');
  doc.setTextColor(232,96,10);doc.text((d.pts||'—')+' pts',M+5,163);
  doc.setTextColor(15,190,124);doc.text((d.viaPct||'—')+'%',M+TW/3+5,163);
  doc.setTextColor(245,158,11);doc.text((d.compPct||'—')+'%',M+2*TW/3+5,163);

  // URL del resultado
  if(vpResultUrl){
    doc.setFontSize(8);doc.setFont('helvetica','normal');doc.setTextColor(232,96,10);
    doc.text('Ver informe online: '+vpResultUrl,M,172);
  }

  var y=180;
  var addSec=function(n,t,b){
    if(y>258){doc.addPage();y=20;doc.setFillColor(247,248,250);doc.rect(0,0,W,297,'F');}
    doc.setFont('helvetica','bold');doc.setFontSize(9.5);doc.setTextColor(7,17,31);
    doc.text((n?n+'. ':'')+t,M,y);y+=5;
    doc.setFillColor(232,96,10);doc.rect(M,y,28,.6,'F');y+=6;
    doc.setFont('helvetica','normal');doc.setFontSize(9);doc.setTextColor(55,65,80);
    var lines=doc.splitTextToSize(b||'—',TW);
    lines.forEach(function(l){
      if(y>268){doc.addPage();y=20;doc.setFillColor(247,248,250);doc.rect(0,0,W,297,'F');}
      doc.text(l,M,y);y+=5;
    });
    y+=4;
  };

  addSec(1,'Naturaleza y alcance',d.alcance);
  addSec(2,'Análisis académico',d.academico);
  addSec(3,'Análisis laboral',d.laboral);

  // Aspectos a trabajar
  if((d.bloqueantes||[]).length>0){
    if(y>230){doc.addPage();y=20;doc.setFillColor(247,248,250);doc.rect(0,0,W,297,'F');}
    var bloqLabel=d.viability==='no-apto'?'Aspectos a fortalecer':'Variables a mejorar';
    doc.setFont('helvetica','bold');doc.setFontSize(9.5);doc.setTextColor(245,158,11);
    doc.text(bloqLabel,M,y);y+=5;
    doc.setFillColor(245,158,11);doc.rect(M,y,28,.6,'F');y+=7;
    (d.bloqueantes||[]).forEach(function(b){
      if(y>260){doc.addPage();y=20;doc.setFillColor(247,248,250);doc.rect(0,0,W,297,'F');}
      doc.setFont('helvetica','bold');doc.setFontSize(9);doc.setTextColor(200,150,20);
      doc.text(b.titulo||b.title||'',M,y);
      doc.setFont('helvetica','normal');doc.setTextColor(70,80,95);
      var bl=doc.splitTextToSize(b.desc||'',TW-2);
      bl.forEach(function(l){y+=5;if(y>268){doc.addPage();y=20;doc.setFillColor(247,248,250);doc.rect(0,0,W,297,'F');}doc.text(l,M+2,y);});
      y+=8;
    });
  }

  addSec('','Variables de competitividad',(d.variables||[]).map(function(v){return v.title+': '+v.desc;}).join(' | '));

  // Visas
  if(y>250){doc.addPage();y=20;doc.setFillColor(247,248,250);doc.rect(0,0,W,297,'F');}
  doc.setFont('helvetica','bold');doc.setFontSize(9.5);doc.setTextColor(7,17,31);
  doc.text('Visas potenciales',M,y);y+=8;
  var vx=M;
  (d.visas||[]).forEach(function(v){
    var vw=doc.getTextWidth(v)+12;
    if(vx+vw>W-M){vx=M;y+=10;}
    doc.setFillColor(232,96,10);doc.roundedRect(vx,y-6,vw,8,1.5,1.5,'F');
    doc.setFont('helvetica','bold');doc.setFontSize(8);doc.setTextColor(255,255,255);
    doc.text(v,vx+6,y);vx+=vw+6;
  });y+=14;

  // Recomendaciones
  addSec('','Recomendaciones',(d.recomendaciones||[]).map(function(r){return r.texto;}).join(' | '));

  // Footer
  var pages=doc.getNumberOfPages();
  for(var i=1;i<=pages;i++){
    doc.setPage(i);
    doc.setFillColor(7,17,31);doc.rect(0,286,W,11,'F');
    doc.setFont('helvetica','normal');doc.setFontSize(7);doc.setTextColor(122,142,168);
    doc.text('© 2026 Viva Australia Internacional · MARA 0101111 · Informe orientativo generado por IA.',M,292);
    doc.text('Pág. '+i+'/'+pages,W-M,292,{align:'right'});
  }

  doc.save('Pre-EVM_'+(d.nom||'')+'_'+(d.ape||'')+'.pdf');
}

// ══════════════════════════════════════════════════════
// RESET
// ══════════════════════════════════════════════════════
function vpReset(){
  vpData={};vpContact=null;vpResult=null;vpResultUrl=null;
  vpCvFile=null;vpCvBase64=null;vpCvMime=null;vpModoCV='cv';
  vpQuizIdx=0;vpQuizHistory=[];
  // Limpiar inputs
  var ids=['vpEmail','vpNombre','vpApellido','vpWA','vpProfesion','vpTitulo','vpPostgrado','vpEmpresa','vpCargo','vpDesc'];
  ids.forEach(function(id){var el=document.getElementById(id);if(el){el.value='';el.readOnly=false;}});
  ['vpPais','vpEdad','vpIngles','vpExp'].forEach(function(id){var el=document.getElementById(id);if(el)el.value='';});
  ['vpNombreBadge','vpApellidoBadge'].forEach(function(id){var el=document.getElementById(id);if(el)el.style.display='none';});
  var gr=document.getElementById('vpGHLGreeting');if(gr)gr.classList.remove('show');
  var drop=document.getElementById('vpDrop');
  if(drop){drop.classList.remove('ok','over');
    document.getElementById('vpDropIco').textContent='📄';
    document.getElementById('vpDropTitle').textContent='Arrastra tu CV aquí o haz clic para subir';
    document.getElementById('vpDropSub').textContent='PDF recomendado · DOC, DOCX o TXT · Máx. 10 MB';
  }
  var inp=document.getElementById('vpCvInput');if(inp)inp.value='';
  var mf=document.getElementById('vpManualFields');if(mf)mf.style.display='none';
  vpGoScreen('s1');vpUpdateSteps(1);
  document.getElementById('viva-preevm-app').scrollIntoView({behavior:'smooth',block:'start'});
}

// ══════════════════════════════════════════════════════
// API HELPER
// ══════════════════════════════════════════════════════
function vpApi(endpoint,data,jsonBody){
  var url=vpCfg.restUrl+endpoint;
  var opts={method:'POST',headers:{'X-WP-Nonce':vpCfg.nonce,'Content-Type':'application/json'}};
  opts.body=JSON.stringify(data);
  if(endpoint==='analyze'){
    console.group('[VIVA PRE-EVM] 📤 Enviando a /analyze');
    var logPayload=Object.assign({},data);
    if(logPayload.cvBase64) logPayload.cvBase64='[BASE64 '+Math.round(logPayload.cvBase64.length*0.75/1024)+'KB omitido]';
    console.log('Payload:', logPayload);
    console.groupEnd();
  }
  return fetch(url,opts).then(function(r){
    return r.json().then(function(json){
      if(!r.ok){
        if(endpoint==='analyze'){
          console.error('[VIVA PRE-EVM] ❌ Error HTTP '+r.status+' en /analyze:', json);
        }
        throw new Error(json.message||json.data?.message||'Error '+r.status);
      }
      if(endpoint==='analyze'){
        console.group('[VIVA PRE-EVM] ✅ Respuesta RAW de /analyze');
        console.log('viability:', json.viability);
        console.log('pts:', json.pts, '| viaPct:', json.viaPct, '| compPct:', json.compPct);
        console.log('desglosePuntos:', json.desglosePuntos);
        console.log('anzsco:', json.anzsco);
        console.log('visas:', json.visas);
        console.log('Objeto completo:', JSON.parse(JSON.stringify(json)));
        console.groupEnd();
      }
      return json;
    });
  });
}

// ══════════════════════════════════════════════════════
// ARRANCAR
// ══════════════════════════════════════════════════════
document.addEventListener('DOMContentLoaded',vpInit);
// Exponer funciones globalmente (necesarias para onclick en HTML)
window.vpHandleStep1=vpHandleStep1;
window.vpHandleStep2=vpHandleStep2;
window.vpGoScreen=vpGoScreen;
window.vpQuizNext=vpQuizNext;
window.vpQuizPrev=vpQuizPrev;
window.vpSelectPill=vpSelectPill;
window.vpSelectScale=vpSelectScale;
window.vpHandleAnalyze=vpHandleAnalyze;
window.vpShowManualFields=vpShowManualFields;
window.vpContinueLater=vpContinueLater;
window.vpLaunchAnalysis=vpLaunchAnalysis;
window.vpGeneratePDF=vpGeneratePDF;
window.vpReset=vpReset;
window.vpScrollToCalendar=vpScrollToCalendar;

// ── Mapa de escasez por estado ───────────────────────────────
function vpRenderShortageMap(d, containerId){
  var container=document.getElementById(containerId);
  if(!container) return;
  var smap=d.shortageMap;
  if(!smap || !Object.keys(smap).length) return;

  var STATES=['NSW','VIC','QLD','SA','WA','TAS','NT','ACT'];
  var RATING_ICO={S:'🟢',R:'🔵',M:'🟡',NS:'⚪'};
  var RATING_LBL={S:'Escasez',R:'Regional',M:'Metrópolis',NS:'Sin escasez'};
  var DEMAND_LBL={very_high:'Escasez en casi todo el país',high:'Escasez en la mayoría de estados',moderate:'Escasez en algunos estados',some:'Escasez puntual',none:'Sin escasez detectada'};

  var html='<div class="vp-shortage-wrap"><div class="vp-shortage-title">🗺️ Demanda laboral por estado (OSL 2025)</div>';

  Object.keys(smap).forEach(function(code){
    var sh=smap[code];
    // Buscar nombre del código en d.anzsco
    var azName='';
    (d.anzsco||[]).forEach(function(a){ if(a.code===code) azName=a.name; });

    var natIco=RATING_ICO[sh.national]||'⚪';
    var natLbl=RATING_LBL[sh.national]||sh.national;
    var demLbl=DEMAND_LBL[sh.demandLevel]||'';
    var jsaUrl='https://www.jobsandskills.gov.au/jobs-and-skills-atlas/occupation?occupationFocus='+code.substring(0,4);

    html+='<div class="vp-shortage-occ">';
    html+='<div class="vp-shortage-occ-h">';
    html+='<span class="vp-az-code">'+code+'</span>';
    if(azName) html+='<span class="vp-shortage-occ-name">'+azName+'</span>';
    html+='<span class="vp-shortage-nat">'+natIco+' Nacional: '+natLbl+'</span>';
    html+='</div>';

    html+='<div class="vp-shortage-states">';
    STATES.forEach(function(s){
      var r=(sh.byState&&sh.byState[s])||'NS';
      var ico=RATING_ICO[r]||'⚪';
      var lbl=RATING_LBL[r]||r;
      html+='<div class="vp-ss '+r.toLowerCase()+'"><span>'+ico+'</span><span class="vp-ss-code">'+s+'</span><span class="vp-ss-lbl">'+lbl+'</span></div>';
    });
    html+='</div>';

    if(demLbl) html+='<div class="vp-shortage-demand">📊 '+demLbl+'</div>';
    html+='<div class="vp-shortage-jsa"><a href="'+jsaUrl+'" target="_blank" rel="noopener">Ver en Jobs and Skills Atlas →</a></div>';
    html+='</div>';
  });

  html+='</div>';
  container.insertAdjacentHTML('beforeend', html);
}

// ── Scroll al calendario y auto-resize ───────────────────────────────────────
function vpScrollToCalendar(){
  var wrap=document.getElementById('vpCalWrap');
  if(wrap) wrap.scrollIntoView({behavior:'smooth',block:'start'});
}
// Auto-resize del iframe del calendario via postMessage
window.addEventListener('message',function(e){
  if(!e.data) return;
  var d=e.data;
  // GHL / Cal.com / Calendly envían height en el mensaje
  var h=0;
  if(typeof d==='object' && d.height)  h=parseInt(d.height);
  if(typeof d==='object' && d.iFrameHeight) h=parseInt(d.iFrameHeight);
  if(typeof d==='string'){
    try{ var p=JSON.parse(d); if(p.height) h=parseInt(p.height); }catch(x){}
  }
  if(h>400){
    var iframe=document.getElementById('vpCalIframe');
    if(iframe) iframe.style.height=(h+40)+'px';
  }
});

})();
</script>

VPHTML;

    return ob_get_clean();
}
