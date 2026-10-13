<?php
/**
 * Form controls of the settings page.
 *
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Admin;

use PrstSearch\Settings\Settings;

/**
 * Prints accessible form controls bound to settings.
 *
 * Every control is a native element with a visible label; descriptions are
 * connected with aria-describedby.
 */
final class Fields {

	public const INPUT_NAME = 'prstsearch';

	/**
	 * Creates the helper.
	 */
	public function __construct( private readonly Settings $settings ) {}

	/**
	 * Prints a row with an on/off switch.
	 */
	public function switch_row( string $key, string $label, string $description ): void {
		$id = self::id( $key );
		?>
		<div class="prstsearch-row">
			<div class="prstsearch-row__text">
				<label class="prstsearch-row__label" for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label>
				<p class="prstsearch-row__description" id="<?php echo esc_attr( $id ); ?>-description"><?php echo esc_html( $description ); ?></p>
			</div>
			<input
				class="prstsearch-switch"
				type="checkbox"
				role="switch"
				id="<?php echo esc_attr( $id ); ?>"
				name="<?php echo esc_attr( self::name( $key ) ); ?>"
				value="1"
				aria-describedby="<?php echo esc_attr( $id ); ?>-description"
				<?php checked( $this->settings->bool( $key ) ); ?>
			/>
		</div>
		<?php
	}

	/**
	 * Prints a row with a text, number, url or password input.
	 *
	 * @param string               $key         Setting name.
	 * @param string               $label       Visible label.
	 * @param string               $description Help text below the label.
	 * @param array<string, mixed> $attributes  Additional attributes of the input, e.g. type, min, placeholder, value.
	 *                                          The key row_class is not printed on the input; it adds a class to the row.
	 */
	public function input_row( string $key, string $label, string $description, array $attributes = array() ): void {
		$id         = self::id( $key );
		$attributes = $attributes + array(
			'type'  => 'text',
			'value' => (string) $this->settings->get( $key ),
			'name'  => self::name( $key ),
		);
		$modifier   = (string) ( $attributes['row_class'] ?? '' );

		unset( $attributes['row_class'] );
		?>
		<div class="prstsearch-row <?php echo esc_attr( $modifier ); ?>">
			<div class="prstsearch-row__text">
				<label class="prstsearch-row__label" for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label>
				<p class="prstsearch-row__description" id="<?php echo esc_attr( $id ); ?>-description"><?php echo esc_html( $description ); ?></p>
			</div>
			<input
				class="prstsearch-input"
				id="<?php echo esc_attr( $id ); ?>"
				aria-describedby="<?php echo esc_attr( $id ); ?>-description"
				<?php self::attributes( $attributes ); ?>
			/>
		</div>
		<?php
	}

	/**
	 * Prints a row with a multi-line text field below the label.
	 */
	public function textarea_row( string $key, string $label, string $description ): void {
		$id = self::id( $key );
		?>
		<div class="prstsearch-row prstsearch-row--stacked">
			<div class="prstsearch-row__text">
				<label class="prstsearch-row__label" for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label>
				<p class="prstsearch-row__description" id="<?php echo esc_attr( $id ); ?>-description"><?php echo esc_html( $description ); ?></p>
			</div>
			<textarea
				class="prstsearch-input prstsearch-input--textarea"
				id="<?php echo esc_attr( $id ); ?>"
				name="<?php echo esc_attr( self::name( $key ) ); ?>"
				rows="3"
				aria-describedby="<?php echo esc_attr( $id ); ?>-description"
			><?php echo esc_textarea( $this->settings->string( $key ) ); ?></textarea>
		</div>
		<?php
	}

	/**
	 * Prints a row with a select.
	 *
	 * @param string                $key         Setting name.
	 * @param string                $label       Visible label.
	 * @param string                $description Help text below the label.
	 * @param array<string, string> $choices     Labels keyed by value.
	 */
	public function select_row( string $key, string $label, string $description, array $choices ): void {
		$id = self::id( $key );
		?>
		<div class="prstsearch-row">
			<div class="prstsearch-row__text">
				<label class="prstsearch-row__label" for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label>
				<p class="prstsearch-row__description" id="<?php echo esc_attr( $id ); ?>-description"><?php echo esc_html( $description ); ?></p>
			</div>
			<select
				class="prstsearch-input"
				id="<?php echo esc_attr( $id ); ?>"
				name="<?php echo esc_attr( self::name( $key ) ); ?>"
				aria-describedby="<?php echo esc_attr( $id ); ?>-description"
			>
				<?php foreach ( $choices as $value => $choice_label ) : ?>
					<option value="<?php echo esc_attr( (string) $value ); ?>" <?php selected( (string) $this->settings->get( $key ), (string) $value ); ?>>
						<?php echo esc_html( $choice_label ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</div>
		<?php
	}

	/**
	 * Prints a group of selectable cards.
	 *
	 * @param string                                                                               $key     Setting name.
	 * @param string                                                                               $legend  Name of the group, read by screen readers.
	 * @param array<string, array{title: string, description: string, note: string, icon: string}> $choices Cards keyed by value.
	 * @param bool                                                                                 $multiple Whether several cards can be selected (checkboxes) or one (radios).
	 * @param string[]|null                                                                        $selected Selected values, if they differ from what is stored for the setting.
	 */
	public function cards( string $key, string $legend, array $choices, bool $multiple = false, ?array $selected = null ): void {
		$selected ??= $multiple ? $this->settings->list( $key ) : array( $this->settings->string( $key ) );
		$name       = self::name( $key ) . ( $multiple ? '[]' : '' );
		?>
		<fieldset class="prstsearch-cards" data-prstsearch-setting="<?php echo esc_attr( $key ); ?>">
			<legend class="screen-reader-text"><?php echo esc_html( $legend ); ?></legend>
			<?php foreach ( $choices as $value => $choice ) : ?>
				<?php $id = self::id( $key . '-' . $value ); ?>
				<label class="prstsearch-card" for="<?php echo esc_attr( $id ); ?>">
					<input
						class="prstsearch-card__input"
						type="<?php echo $multiple ? 'checkbox' : 'radio'; ?>"
						id="<?php echo esc_attr( $id ); ?>"
						name="<?php echo esc_attr( $name ); ?>"
						value="<?php echo esc_attr( (string) $value ); ?>"
						<?php checked( in_array( (string) $value, $selected, true ) ); ?>
					/>
					<span class="prstsearch-card__icon"><?php echo Icons::svg( $choice['icon'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG. ?></span>
					<span class="prstsearch-card__title"><?php echo esc_html( $choice['title'] ); ?></span>
					<span class="prstsearch-card__description">
						<?php echo esc_html( $choice['description'] ); ?>
						<span class="prstsearch-card__note"><?php echo esc_html( $choice['note'] ); ?></span>
					</span>
				</label>
			<?php endforeach; ?>
		</fieldset>
		<?php
	}

	/**
	 * Prints a row with a group of checkboxes.
	 *
	 * @param string                $key         Setting name.
	 * @param string                $label       Name of the group.
	 * @param string                $description Help text.
	 * @param array<string, string> $choices     Labels keyed by value.
	 * @param string[]              $locked      Values that are always on and cannot be changed.
	 */
	public function checkbox_row( string $key, string $label, string $description, array $choices, array $locked = array() ): void {
		$selected = $this->settings->list( $key );
		$group_id = self::id( $key );
		?>
		<div class="prstsearch-row prstsearch-row--group" role="group" aria-labelledby="<?php echo esc_attr( $group_id ); ?>-label" aria-describedby="<?php echo esc_attr( $group_id ); ?>-description">
			<div class="prstsearch-row__text">
				<span class="prstsearch-row__label" id="<?php echo esc_attr( $group_id ); ?>-label"><?php echo esc_html( $label ); ?></span>
				<p class="prstsearch-row__description" id="<?php echo esc_attr( $group_id ); ?>-description"><?php echo esc_html( $description ); ?></p>
			</div>
			<div class="prstsearch-checks">
				<?php foreach ( $choices as $value => $choice_label ) : ?>
					<?php
					$id        = self::id( $key . '-' . $value );
					$is_locked = in_array( (string) $value, $locked, true );
					?>
					<label class="prstsearch-check" for="<?php echo esc_attr( $id ); ?>">
						<input
							type="checkbox"
							id="<?php echo esc_attr( $id ); ?>"
							name="<?php echo esc_attr( self::name( $key ) ); ?>[]"
							value="<?php echo esc_attr( (string) $value ); ?>"
							<?php checked( $is_locked || in_array( (string) $value, $selected, true ) ); ?>
							<?php disabled( $is_locked ); ?>
						/>
						<?php echo esc_html( $choice_label ); ?>
					</label>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Returns the name attribute of a setting's control.
	 */
	public static function name( string $key ): string {
		return self::INPUT_NAME . '[' . $key . ']';
	}

	/**
	 * Returns the id attribute of a setting's control.
	 */
	public static function id( string $key ): string {
		return 'prstsearch-' . str_replace( '_', '-', sanitize_key( $key ) );
	}

	/**
	 * Prints HTML attributes. False and null values are skipped, true prints a boolean attribute.
	 *
	 * @param array<string, mixed> $attributes Attributes.
	 */
	private static function attributes( array $attributes ): void {
		foreach ( $attributes as $name => $value ) {
			if ( false === $value || null === $value ) {
				continue;
			}

			if ( true === $value ) {
				echo ' ' . esc_attr( $name );
				continue;
			}

			printf( ' %s="%s"', esc_attr( $name ), esc_attr( (string) $value ) );
		}
	}
}
