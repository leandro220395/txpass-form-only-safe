<?php
/**
 * Plugin Name: TXPASS - Formulário (Safe) v1.6.1
 * Description: Formulário multi-etapas (AJAX), tema P&B, validação em tempo real, controle de estoque, PIX dinâmico por evento e notificações. Shortcode: [txp_evento_form event_id="..." organizer_id="..." hide_empty_sizes="0|1"]
 * Version: 1.6.1
 * Author: TXPASS
 */

if (!defined('ABSPATH')) exit;
if (version_compare(PHP_VERSION, '7.0.0', '<')) return;

if (!class_exists('TXPFO_Form_161')){
class TXPFO_Form_161 {
    const NONCE_ACTION = 'txpfo_nonce';
    const NONCE_NAME   = 'txpfo_nonce';

    public function __construct(){
        add_shortcode('txp_evento_form', [$this, 'shortcode_form']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_ajax_txpfo_check',       [$this, 'ajax_router']);
        add_action('wp_ajax_nopriv_txpfo_check',[$this, 'ajax_router']);
    }

    public function enqueue_assets(){
        $ver = '1.6.1';
        wp_register_style('txpfo', plugins_url('assets/css/form.css', __FILE__), [], $ver);
        wp_register_script('txpfo', plugins_url('assets/js/form.js', __FILE__), [], $ver, true);
        wp_localize_script('txpfo','txpfoAjax',[
            'ajaxurl'=>admin_url('admin-ajax.php'),
            'nonce'=>wp_create_nonce(self::NONCE_ACTION)
        ]);
    }

    private function get_event_stock_map($event_id){
        $fields = ['estoque_geral','estoque_p','estoque_m','estoque_g','estoque_gg','estoque_xg'];
        $out = ['estoque_geral'=>0,'estoque_p'=>0,'estoque_m'=>0,'estoque_g'=>0,'estoque_gg'=>0,'estoque_xg'=>0];
        foreach($fields as $f){ $out[$f] = intval(get_post_meta($event_id, $f, true)); }
        return $out;
    }

    private function validate_cpf($cpf){
        $cpf = preg_replace('/\D+/', '', $cpf);
        if (strlen($cpf) != 11) return false;
        if (preg_match('/^(\d)\1{10}$/', $cpf)) return false;
        for ($t = 9; $t < 11; $t++) {
            $d = 0;
            for ($c = 0; $c < $t; $c++) $d += $cpf[$c] * (($t + 1) - $c);
            $d = ((10 * $d) % 11) % 10;
            if ($cpf[$c] != $d) return false;
        }
        return true;
    }

    private function email_template_user($data){
        $evt = isset($data['event_title']) ? $data['event_title'] : '';
        $html = '<!DOCTYPE html><html lang="pt-br"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Confirmação de Inscrição</title></head><body style="margin:0; padding:0;"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background-color:#f2f2f2"><tr><td align="center" style="padding:20px;"><table role="presentation" width="600" cellspacing="0" cellpadding="0" border="0" style="background-color:#ffffff; padding:24px; font-family:Arial, sans-serif; border-collapse:collapse; border:1px solid #e5e5e5; border-radius:10px;"><tr><td align="center" style="font-size:22px; color:#111; font-weight:bold;">Confirmação de Inscrição em '.esc_html($evt).' - '.esc_html($data['primeironome']).'</td></tr><tr><td style="font-size:16px; color:#333; padding:16px 0;">Olá, '.esc_html($data['primeironome']).'!<br><br>Recebemos sua inscrição para <strong>'.esc_html($evt).'</strong>. Sua inscrição foi registrada com as seguintes informações:</td></tr><tr><td style="font-size:16px; color:#333; line-height:1.6"><strong>Nome:</strong> '.esc_html($data['primeironome']).' '.esc_html($data['segundonome']).'<br><strong>CPF:</strong> '.esc_html($data['_cpf']).'<br><strong>E-mail:</strong> '.esc_html($data['aemail']).'<br><strong>Celular:</strong> '.esc_html($data['ncelulartm']).'<br><strong>Cidade/Estado:</strong> '.esc_html($data['cidadetm']).', '.esc_html($data['estado_']).'<br><strong>Nome do Grupo:</strong> '.esc_html($data['nomedogrupotm']).'<br><strong>Tamanho da Camiseta:</strong> '.esc_html($data['tcamisetatm']).'<br></td></tr><tr><td style="border-top:1px dashed #e5e5e5; padding:10px 0;"></td></tr><tr><td style="font-size:15px; color:#333; padding:10px 0;"><strong>Importante:</strong> Sua inscrição só será válida após o pagamento e envio do comprovante PIX via WhatsApp.</td></tr><tr><td align="center" style="padding:16px 0; text-align:center;"><a href="https://wa.me/55'.esc_attr($data['wa']).'?text=Ol%C3%A1,%20enviei%20o%20comprovante%20da%20inscri%C3%A7%C3%A3o." style="background-color:#25D366; color:#0b2314; text-decoration:none; font-size:16px; padding:12px 20px; display:inline-block; border-radius:6px; font-weight:bold;">Enviar Comprovante PIX</a></td></tr><tr><td align="center" style="font-size:14px; color:#666; padding-top:24px;">Obrigado pela inscrição! — <strong>Equipe TXPASS</strong></td></tr></table></td></tr></table></body></html>';
        return $html;
    }

    private function email_template_admin($data){
        $evt = isset($data['event_title']) ? $data['event_title'] : '';
        $wa_clean = preg_replace('/\D+/', '', $data['ncelulartm']);
        $wa_link  = 'https://wa.me/55'.$wa_clean.'?text=Ol%C3%A1%2C%20vi%20sua%20inscri%C3%A7%C3%A3o%20em%20'.rawurlencode($evt).'.';
        $html = '<!DOCTYPE html><html lang="pt-br"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Nova Inscrição Recebida</title></head><body style="margin:0; padding:0; background:#f5f5f5; font-family:Arial, sans-serif; color:#111"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background-color:#f5f5f5"><tr><td align="center" style="padding:24px"><table role="presentation" width="640" cellspacing="0" cellpadding="0" border="0" style="background:#fff; border:1px solid #e5e5e5; border-radius:10px; padding:24px"><tr><td style="font-size:22px; font-weight:bold; padding-bottom:8px; text-align:center">Nova inscrição em '.esc_html($evt).'</td></tr><tr><td style="font-size:15px; color:#333; padding-bottom:16px; text-align:center">Você recebeu uma nova inscrição. Veja os detalhes:</td></tr><tr><td style="font-size:15px; color:#111; line-height:1.6"><strong>Nome:</strong> '.esc_html($data['primeironome']).' '.esc_html($data['segundonome']).'<br><strong>CPF:</strong> '.esc_html($data['_cpf']).'<br><strong>E-mail:</strong> '.esc_html($data['aemail']).'<br><strong>WhatsApp:</strong> '.esc_html($data['ncelulartm']).'<br><strong>Cidade/Estado:</strong> '.esc_html($data['cidadetm']).', '.esc_html($data['estado_']).'<br><strong>Nome do Grupo:</strong> '.esc_html($data['nomedogrupotm']).'<br><strong>Tamanho da Camiseta:</strong> '.esc_html($data['tcamisetatm']).'<br></td></tr><tr><td style="padding:18px 0 6px 0; border-top:1px dashed #e5e5e5"></td></tr><tr><td align="center" style="padding-top:8px; text-align:center"><a href="'.$wa_link.'" style="background:#25D366; color:#0b2314; text-decoration:none; font-size:15px; padding:12px 18px; display:inline-block; border-radius:8px; font-weight:bold">Enviar mensagem no WhatsApp</a></td></tr></table></td></tr></table></body></html>';
        return $html;
    }

    public function ajax_router(){
        if (!isset($_POST[self::NONCE_NAME]) || !wp_verify_nonce($_POST[self::NONCE_NAME], self::NONCE_ACTION)){
            wp_send_json_error(['message'=>'Falha de segurança']); }
        $op = isset($_POST['op']) ? sanitize_text_field($_POST['op']) : '';

        if ($op === 'cpf'){
            $cpf = sanitize_text_field($_POST['cpf']??'');
            $valid = $this->validate_cpf($cpf);
            $exists = false;
            if ($valid){
                $clean = preg_replace('/\D+/', '', $cpf);
                $dup = new WP_Query([
                    'post_type'=>'inscricoes','posts_per_page'=>1,
                    'meta_query'=>[ [ 'key'=>'_cpf', 'value'=>$clean, 'compare'=>'LIKE' ] ]
                ]);
                $exists = $dup->have_posts();
            }
            wp_send_json_success(['valid'=>$valid, 'duplicate'=>$exists]);
        }

        if ($op === 'stock'){
            $event_id = intval($_POST['event_id']??0);
            $stock = $this->get_event_stock_map($event_id);
            $avail = [
                'P'  => ($stock['estoque_p']  > 0 && $stock['estoque_geral']>0),
                'M'  => ($stock['estoque_m']  > 0 && $stock['estoque_geral']>0),
                'G'  => ($stock['estoque_g']  > 0 && $stock['estoque_geral']>0),
                'GG' => ($stock['estoque_gg'] > 0 && $stock['estoque_geral']>0),
                'XG' => ($stock['estoque_xg'] > 0 && $stock['estoque_geral']>0),
            ];
            wp_send_json_success(['stock'=>$stock, 'available'=>$avail]);
        }

        if ($op === 'finalize'){
            $event_id = intval($_POST['event_id']??0);
            $org_id   = intval($_POST['organizer_id']??0);

            // Valores oficiais vindos do evento
            $pix_code = get_post_meta($event_id, 'codigo-pix', true);
            $pix_name = get_post_meta($event_id, 'nome-do-recebedor', true);
            $amount   = get_post_meta($event_id, 'valor', true);
            $wa       = preg_replace('/\D+/', '', (string)get_post_meta($event_id, 'telefone-do-organizador', true));
            $emailadm = sanitize_email((string)get_post_meta($event_id, 'email-organizador', true));

            $first = sanitize_text_field($_POST['primeironome']??'');
            $last  = sanitize_text_field($_POST['segundonome']??'');
            $cpf   = sanitize_text_field($_POST['_cpf']??'');
            $email = sanitize_email($_POST['aemail']??'');
            $size  = sanitize_text_field($_POST['tcamisetatm']??'');
            $phone = sanitize_text_field($_POST['ncelulartm']??'');
            $city  = sanitize_text_field($_POST['cidadetm']??'');
            $state = sanitize_text_field($_POST['estado_']??'');
            $group = sanitize_text_field($_POST['nomedogrupotm']??'');

            if (!$first || !$last || !$email || !$cpf || !$phone || !$city || !$state || !$size){
                wp_send_json_error(['message'=>'Preencha todos os campos obrigatórios.']);
            }
            if (!$this->validate_cpf($cpf)){
                wp_send_json_error(['message'=>'CPF inválido.']);
            }
            $dup = new WP_Query([
                'post_type'=>'inscricoes','posts_per_page'=>1,
                'meta_query'=>[ [ 'key'=>'_cpf', 'value'=>preg_replace('/\D+/','',$cpf), 'compare'=>'LIKE' ] ]
            ]);
            if ($dup->have_posts()){
                wp_send_json_error(['message'=>'CPF já possui inscrição.']);
            }

            // Estoque
            $stock = $this->get_event_stock_map($event_id);
            $map   = ['P'=>'estoque_p','M'=>'estoque_m','G'=>'estoque_g','GG'=>'estoque_gg','XG'=>'estoque_xg'];
            $key   = isset($map[$size]) ? $map[$size] : '';
            if (!$key || $stock['estoque_geral'] <= 0 || $stock[$key] <= 0){
                wp_send_json_error(['message'=>'Tamanho indisponível.']);
            }

            // Criar inscrição
            $post_id = wp_insert_post([ 'post_type'=>'inscricoes','post_title'=>$first,'post_status'=>'publish' ], true);
            if (is_wp_error($post_id)){
                wp_send_json_error(['message'=>'Falha ao registrar inscrição.']);
            }

            update_post_meta($post_id, 'primeironome', $first);
            update_post_meta($post_id, 'segundonome', $last);
            update_post_meta($post_id, '_cpf', $cpf);
            update_post_meta($post_id, 'aemail', $email);
            update_post_meta($post_id, 'tcamisetatm', $size);
            update_post_meta($post_id, 'ncelulartm', $phone);
            update_post_meta($post_id, 'cidadetm', $city);
            update_post_meta($post_id, 'estado_', $state);
            update_post_meta($post_id, 'nomedogrupotm', $group);
            update_post_meta($post_id, 'id_do_organizador', $org_id);
            update_post_meta($post_id, 'status-pedidoo', 'Pendente');

            // Lote condicional
            $possui_lote = get_post_meta($event_id, 'possui-lote', true);
            $lote_n  = get_post_meta($event_id, 'lote_n', true);
            if (is_string($possui_lote) && in_array(strtolower($possui_lote), ['sim','yes','1','true'])){
                update_post_meta($post_id, 'lote_', $lote_n);
            }

            // Decrementa estoque
            update_post_meta($event_id, 'estoque_geral', max(0, intval($stock['estoque_geral']) - 1));
            update_post_meta($event_id, $key,           max(0, intval($stock[$key]) - 1));

            // Emails
            $headers = ['Content-Type: text/html; charset=UTF-8'];
            $event_title = get_the_title($event_id);
            @wp_mail($email, 'Recebemos sua inscrição em '.$event_title.', '.$first.'!', $this->email_template_user([
                'primeironome'=>$first,'segundonome'=>$last,'_cpf'=>$cpf,'aemail'=>$email,'ncelulartm'=>$phone,
                'cidadetm'=>$city,'estado_'=>$state,'nomedogrupotm'=>$group,'tcamisetatm'=>$size,'wa'=>$wa,'event_title'=>$event_title
            ]), $headers);

            if (!empty($emailadm) && is_email($emailadm)){
                @wp_mail($emailadm, 'Nova inscrição em '.$event_title.': '.$first.' '.$last, $this->email_template_admin([
                    'primeironome'=>$first,'segundonome'=>$last,'_cpf'=>$cpf,'aemail'=>$email,'ncelulartm'=>$phone,
                    'cidadetm'=>$city,'estado_'=>$state,'nomedogrupotm'=>$group,'tcamisetatm'=>$size,'wa'=>$wa,'event_title'=>$event_title
                ]), $headers);
            }

            wp_send_json_success([
                'ok'=>true,
                'reg_id'=>$post_id,
                'event_id'=>$event_id,
                'amount'=>$amount,
                'pix_code'=>$pix_code,
                'pix_name'=>$pix_name,
                'wa'=>$wa
            ]);
        }

        wp_send_json_error(['message'=>'Operação inválida']);
    }

    public function shortcode_form($atts){
        $atts = shortcode_atts([
            'event_id'     => 0,
            'organizer_id' => 0,
            'hide_empty_sizes' => '0'
        ], $atts);

        $event_id = intval($atts['event_id']);
        if (!$event_id){ return '<div class="txp-soldout"><div class="txp-soldout-card"><h3>Configuração inválida</h3><p>Defina event_id no shortcode.</p></div></div>'; }
        $organizer_id = intval($atts['organizer_id']);

        wp_enqueue_style('txpfo');
        wp_enqueue_script('txpfo');
        $nonce = wp_create_nonce(self::NONCE_ACTION);

        // Valores do evento (server-side)
        $pix_code = get_post_meta($event_id, 'codigo-pix', true);
        $pix_name = get_post_meta($event_id, 'nome-do-recebedor', true);
        $amount   = get_post_meta($event_id, 'valor', true);
        $wa       = preg_replace('/\D+/', '', (string)get_post_meta($event_id, 'telefone-do-organizador', true));
        $emailadm = sanitize_email((string)get_post_meta($event_id, 'email-organizador', true));

        $hide_empty = (isset($atts['hide_empty_sizes']) && in_array(strval($atts['hide_empty_sizes']), ['1','true','yes'], true)) ? '1' : '0';
        $stock = $this->get_event_stock_map($event_id);

        ob_start(); ?>
        <div class="txp-form-wrap" data-event="<?php echo esc_attr($event_id); ?>">
            <div class="txp-event-title"><?php echo esc_html(get_the_title($event_id)); ?></div>

            <?php if (intval($stock['estoque_geral']) <= 0): ?>
                <div class="txp-soldout"><div class="txp-soldout-card"><h3>Inscrições esgotadas</h3><p>No momento não há vagas disponíveis para este evento.</p></div></div>
                <?php return ob_get_clean(); endif; ?>

            <form class="txp-form" novalidate>
                <input type="hidden" name="<?php echo esc_attr(self::NONCE_NAME); ?>" value="<?php echo esc_attr($nonce); ?>">
                <input type="hidden" name="organizer_id" value="<?php echo esc_attr($organizer_id); ?>">

                <!-- espelhos apenas para UI (valores oficiais são lidos do evento no server) -->
                <input type="hidden" name="wa" value="<?php echo esc_attr($wa); ?>">
                <input type="hidden" name="pix_code" value="<?php echo esc_attr($pix_code); ?>">
                <input type="hidden" name="pix_name" value="<?php echo esc_attr($pix_name); ?>">
                <input type="hidden" name="amount" value="<?php echo esc_attr($amount); ?>">
                <input type="hidden" name="emailadm" value="<?php echo esc_attr($emailadm); ?>">

                <div class="steps">
                    <div class="step active" data-step="1">
                        <div class="grid">
                            <label>Nome
                                <input id="primeironome" name="primeironome" type="text" required placeholder="Seu nome"><div class="field-msg" data-for="primeironome"></div>
                            </label>
                            <label>Sobrenome
                                <input id="segundonome" name="segundonome" type="text" required placeholder="Seu sobrenome"><div class="field-msg" data-for="segundonome"></div>
                            </label>
                            <label>CPF
                                <input id="_cpf" name="_cpf" type="text" inputmode="numeric" maxlength="14" placeholder="000.000.000-00" required><div class="field-msg" data-for="_cpf"></div>
                            </label>
                            <label>E-mail
                                <input id="aemail" name="aemail" type="email" inputmode="email" placeholder="voce@exemplo.com" required><div class="field-msg" data-for="aemail"></div>
                            </label>
                            <label>WhatsApp
                                <input id="ncelulartm" name="ncelulartm" type="tel" inputmode="numeric" maxlength="15" placeholder="(DD) 9XXXX-XXXX" required><div class="field-msg" data-for="ncelulartm"></div>
                            </label>
                            <label>Cidade
                                <input id="cidadetm" name="cidadetm" type="text" placeholder="Sua cidade" required><div class="field-msg" data-for="cidadetm"></div>
                            </label>
                            <label>Estado
                                <select id="estado_" name="estado_" required>
                                    <?php $ufs = ['AC','AL','AP','AM','BA','CE','DF','ES','GO','MA','MT','MS','MG','PA','PB','PR','PE','PI','RJ','RN','RS','RO','RR','SC','SP','SE','TO'];
                                    foreach($ufs as $uf){ echo '<option value="'.esc_attr($uf).'">'.$uf.'</option>'; } ?>
                                </select><div class="field-msg" data-for="estado_"></div>
                            </label>
                            <label>Nome do grupo
                                <input id="nomedogrupotm" name="nomedogrupotm" type="text" placeholder="Opcional"><div class="field-msg" data-for="nomedogrupotm"></div>
                            </label>
                        </div>
                        <div class="nav">
                            <button type="button" class="btn next">Continuar</button>
                        </div>
                    </div>

                    <div class="step" data-step="2" hidden>
                        <div class="sizes" id="sizes-wrap" data-hideempty="<?php echo esc_attr($hide_empty); ?>">
                            <?php
                            $sizes = ['P'=>'estoque_p','M'=>'estoque_m','G'=>'estoque_g','GG'=>'estoque_gg','XG'=>'estoque_xg'];
                            foreach($sizes as $label=>$key){
                                $is_zero = ($stock[$key] <= 0);
                                $disabled = ($is_zero || $stock['estoque_geral'] <= 0) ? 'disabled' : '';
                                $cls = $disabled ? 'size disabled soldout' : 'size';
                                if ($is_zero && $hide_empty === '1') { continue; }
                                $qty_text = $is_zero ? '<strong class="sold">Indisponível</strong>' : '<em class="qty" data-size="'.$label.'">'.intval($stock[$key]).'</em> disp.';
                                echo '<label class="'.$cls.'"><input type="radio" name="tcamisetatm" value="'.$label.'" '.$disabled.'> <span>'.$label.'</span> '.$qty_text.'</label>';
                            }
                            ?>
                        </div>
                        <div class="muted">* Tamanhos indisponíveis ficam desativados (ou ocultos se configurado).</div>
                        <div class="field-msg" data-for="tcamisetatm"></div>
                        <div class="nav">
                            <button type="button" class="btn back">Voltar</button>
                            <button type="submit" class="btn primary">Finalizar e gerar PIX</button>
                        </div>
                    </div>
                </div>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }
}
new TXPFO_Form_161();
}
