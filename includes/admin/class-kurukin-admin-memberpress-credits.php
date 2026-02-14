<?php
namespace Kurukin\Core\Admin;

use Kurukin\Core\Integrations\MemberPress_Credits_Integration;
use Kurukin\Core\Services\Credits_Service;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class MemberPress_Credits_Admin {

	const MENU_SLUG        = 'kurukin-memberpress-credits';
	const PARENT_MENU_SLUG = 'kurukin-core';
	const NOTICE_TRANSIENT = 'kurukin_mp_credit_rules_notice_';

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		add_action( 'admin_post_kurukin_save_mp_credit_rules', [ $this, 'handle_save' ] );
	}

	public function register_menu(): void {
		add_menu_page(
			'Kurukin',
			'Kurukin',
			'manage_options',
			self::PARENT_MENU_SLUG,
			[ $this, 'render_page' ],
			'dashicons-chart-line',
			58
		);

		add_submenu_page(
			self::PARENT_MENU_SLUG,
			'Créditos (MemberPress)',
			'Créditos (MemberPress)',
			'manage_options',
			self::MENU_SLUG,
			[ $this, 'render_page' ]
		);
	}

	public function handle_save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'No tienes permisos suficientes.' );
		}

		check_admin_referer( 'kurukin_mp_credit_rules_save' );

		if ( ! empty( $_POST['restore_defaults'] ) ) {
			update_option( MemberPress_Credits_Integration::OPTION_KEY, MemberPress_Credits_Integration::default_rules(), false );
			$this->set_notice( 'success', 'Reglas restauradas a valores por defecto.' );
			$this->redirect_back();
		}

		$raw_rules = isset( $_POST['rules'] ) && is_array( $_POST['rules'] ) ? wp_unslash( $_POST['rules'] ) : [];
		$errors    = [];
		$rules     = $this->sanitize_rules_from_request( $raw_rules, $errors );

		if ( ! empty( $errors ) ) {
			$this->set_notice( 'error', 'No se guardó. Revisa los campos inválidos.', $errors );
			$this->redirect_back();
		}

		update_option( MemberPress_Credits_Integration::OPTION_KEY, $rules, false );
		$this->set_notice( 'success', 'Guardado correctamente.' );
		$this->redirect_back();
	}

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'No tienes permisos suficientes.' );
		}

		MemberPress_Credits_Integration::ensure_default_rules();
		$rules = MemberPress_Credits_Integration::get_rules();
		?>
		<div class="wrap">
			<h1>Créditos (MemberPress)</h1>
			<p>Configura créditos por membresía. Fórmula aplicada: <code>base_credits * (1 + bonus_percent/100)</code>.</p>
			<?php $this->render_notice(); ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="kurukin_save_mp_credit_rules">
				<?php wp_nonce_field( 'kurukin_mp_credit_rules_save' ); ?>

				<table class="widefat striped" id="kurukin-mp-rules-table">
					<thead>
						<tr>
							<th style="width:120px;">ID Producto</th>
							<th>Nombre / Label</th>
							<th style="width:170px;">Créditos base</th>
							<th style="width:140px;">% Bonus</th>
							<th style="width:110px;">Habilitado</th>
							<th style="width:100px;">Acción</th>
						</tr>
					</thead>
					<tbody id="kurukin-mp-rules-body">
						<?php if ( empty( $rules ) ) : ?>
							<?php $this->render_rule_row( 0, [ 'product_id' => '', 'label' => '', 'base_credits' => '0.000000', 'bonus_percent' => '0.000000', 'enabled' => true ] ); ?>
						<?php else : ?>
							<?php foreach ( array_values( $rules ) as $index => $rule ) : ?>
								<?php $this->render_rule_row( $index, $rule ); ?>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>

				<p>
					<button type="button" class="button" id="kurukin-add-rule">Agregar regla</button>
				</p>

				<p class="submit">
					<button type="submit" class="button button-primary">Guardar</button>
					<button type="submit" class="button" name="restore_defaults" value="1" onclick="return confirm('¿Restaurar reglas por defecto?');">Restaurar defaults</button>
				</p>
			</form>
		</div>

		<script>
		(function() {
			var tbody = document.getElementById('kurukin-mp-rules-body');
			var addBtn = document.getElementById('kurukin-add-rule');
			if (!tbody || !addBtn) return;

			function nextIndex() {
				return tbody.querySelectorAll('tr').length;
			}

			function makeRow(index) {
				var tr = document.createElement('tr');
				tr.innerHTML = ''
					+ '<td><input type="number" min="1" step="1" class="small-text" name="rules[' + index + '][product_id]" required></td>'
					+ '<td><input type="text" class="regular-text" name="rules[' + index + '][label]" placeholder="Ej: Lite Mensual"></td>'
					+ '<td><input type="text" class="regular-text" name="rules[' + index + '][base_credits]" value="0.000000" required></td>'
					+ '<td><input type="text" class="regular-text" name="rules[' + index + '][bonus_percent]" value="0.000000" required></td>'
					+ '<td><label><input type="checkbox" name="rules[' + index + '][enabled]" value="1" checked> Sí</label></td>'
					+ '<td><button type="button" class="button-link-delete kurukin-remove-row">Eliminar</button></td>';
				return tr;
			}

			addBtn.addEventListener('click', function() {
				tbody.appendChild(makeRow(nextIndex()));
			});

			tbody.addEventListener('click', function(event) {
				if (!event.target.classList.contains('kurukin-remove-row')) return;
				event.preventDefault();
				var row = event.target.closest('tr');
				if (row) row.remove();
			});
		})();
		</script>
		<?php
	}

	private function render_rule_row( int $index, array $rule ): void {
		$product_id    = isset( $rule['product_id'] ) ? (int) $rule['product_id'] : 0;
		$label         = isset( $rule['label'] ) ? (string) $rule['label'] : '';
		$base_credits  = isset( $rule['base_credits'] ) ? (string) $rule['base_credits'] : '0.000000';
		$bonus_percent = isset( $rule['bonus_percent'] ) ? (string) $rule['bonus_percent'] : '0.000000';
		$enabled       = ! empty( $rule['enabled'] );
		?>
		<tr>
			<td>
				<input type="number" min="1" step="1" class="small-text" name="rules[<?php echo esc_attr( (string) $index ); ?>][product_id]" value="<?php echo esc_attr( $product_id > 0 ? (string) $product_id : '' ); ?>" required>
			</td>
			<td>
				<input type="text" class="regular-text" name="rules[<?php echo esc_attr( (string) $index ); ?>][label]" value="<?php echo esc_attr( $label ); ?>" placeholder="Ej: Lite Mensual">
			</td>
			<td>
				<input type="text" class="regular-text" name="rules[<?php echo esc_attr( (string) $index ); ?>][base_credits]" value="<?php echo esc_attr( $base_credits ); ?>" required>
			</td>
			<td>
				<input type="text" class="regular-text" name="rules[<?php echo esc_attr( (string) $index ); ?>][bonus_percent]" value="<?php echo esc_attr( $bonus_percent ); ?>" required>
			</td>
			<td>
				<label>
					<input type="checkbox" name="rules[<?php echo esc_attr( (string) $index ); ?>][enabled]" value="1" <?php checked( $enabled ); ?>>
					Sí
				</label>
			</td>
			<td>
				<button type="button" class="button-link-delete kurukin-remove-row">Eliminar</button>
			</td>
		</tr>
		<?php
	}

	private function sanitize_rules_from_request( array $rows, array &$errors ): array {
		$out         = [];
		$seen_ids    = [];
		$row_number  = 0;

		foreach ( $rows as $row ) {
			$row_number++;

			if ( ! is_array( $row ) ) {
				continue;
			}

			$raw_product = isset( $row['product_id'] ) ? trim( (string) $row['product_id'] ) : '';
			$raw_label   = isset( $row['label'] ) ? (string) $row['label'] : '';
			$raw_base    = isset( $row['base_credits'] ) ? trim( (string) $row['base_credits'] ) : '';
			$raw_bonus   = isset( $row['bonus_percent'] ) ? trim( (string) $row['bonus_percent'] ) : '';
			$enabled     = ! empty( $row['enabled'] );

			if ( $raw_product === '' && $raw_label === '' && $raw_base === '' && $raw_bonus === '' ) {
				continue;
			}

			$product_id = absint( $raw_product );
			if ( $product_id <= 0 ) {
				$errors[] = 'Fila ' . $row_number . ': product_id debe ser un entero mayor a 0.';
				continue;
			}

			if ( isset( $seen_ids[ $product_id ] ) ) {
				$errors[] = 'Fila ' . $row_number . ': product_id duplicado (' . $product_id . ').';
				continue;
			}

			$base_clean = $this->parse_numeric_string( $raw_base );
			if ( null === $base_clean ) {
				$errors[] = 'Fila ' . $row_number . ': créditos base inválido.';
				continue;
			}

			$bonus_clean = $this->parse_numeric_string( $raw_bonus );
			if ( null === $bonus_clean ) {
				$errors[] = 'Fila ' . $row_number . ': % bonus inválido.';
				continue;
			}

			if ( (float) $base_clean < 0 ) {
				$errors[] = 'Fila ' . $row_number . ': créditos base no puede ser negativo.';
				continue;
			}

			if ( (float) $bonus_clean < 0 ) {
				$errors[] = 'Fila ' . $row_number . ': % bonus no puede ser negativo.';
				continue;
			}

			$seen_ids[ $product_id ] = true;
			$out[] = [
				'product_id'    => $product_id,
				'label'         => sanitize_text_field( $raw_label ),
				'base_credits'  => Credits_Service::normalize_decimal_6( $base_clean ),
				'bonus_percent' => Credits_Service::normalize_decimal_6( $bonus_clean ),
				'enabled'       => $enabled,
			];
		}

		return $out;
	}

	private function parse_numeric_string( string $value ): ?string {
		$normalized = str_replace( ',', '.', trim( $value ) );
		if ( $normalized === '' ) {
			return null;
		}

		if ( ! preg_match( '/^-?\d+(?:\.\d+)?$/', $normalized ) ) {
			return null;
		}

		return $normalized;
	}

	private function render_notice(): void {
		$key    = self::NOTICE_TRANSIENT . get_current_user_id();
		$notice = get_transient( $key );

		if ( ! is_array( $notice ) ) {
			return;
		}

		delete_transient( $key );

		$type    = ( isset( $notice['type'] ) && $notice['type'] === 'error' ) ? 'notice-error' : 'notice-success';
		$message = isset( $notice['message'] ) ? (string) $notice['message'] : '';
		$details = isset( $notice['details'] ) && is_array( $notice['details'] ) ? $notice['details'] : [];
		?>
		<div class="notice <?php echo esc_attr( $type ); ?> is-dismissible">
			<p><?php echo esc_html( $message ); ?></p>
			<?php if ( ! empty( $details ) ) : ?>
				<ul style="margin-left:18px;">
					<?php foreach ( $details as $detail ) : ?>
						<li><?php echo esc_html( (string) $detail ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
		<?php
	}

	private function set_notice( string $type, string $message, array $details = [] ): void {
		$key = self::NOTICE_TRANSIENT . get_current_user_id();
		set_transient(
			$key,
			[
				'type'    => $type,
				'message' => $message,
				'details' => $details,
			],
			90
		);
	}

	private function redirect_back(): void {
		$url = admin_url( 'admin.php?page=' . self::MENU_SLUG );
		wp_safe_redirect( $url );
		exit;
	}
}

