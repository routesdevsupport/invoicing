<?php
/**
 * Processes taxes for a payment form submission.
 *
 */

defined( 'ABSPATH' ) || exit;

/**
 * Payment form submission taxes class
 *
 */
class GetPaid_Payment_Form_Submission_Taxes {

	/**
	 * Submission taxes.
	 * @var array
	 */
	public $taxes = array();

    /**
	 * Class constructor
	 *
	 * @param GetPaid_Payment_Form_Submission $submission
	 */
	public function __construct( $submission ) {

		// Validate VAT number.
		$this->validate_vat( $submission );

		foreach ( $submission->get_items() as $item ) {
			$this->process_item_tax( $item, $submission );
		}

		// Process any existing invoice taxes.
		if ( $submission->has_invoice() ) {
			$this->taxes = array_replace( $submission->get_invoice()->get_taxes(), $this->taxes );
		}

	}

	/**
	 * Maybe process tax.
	 *
	 * @since 1.0.19
	 * @param GetPaid_Form_Item $item
	 * @param GetPaid_Payment_Form_Submission $submission
	 */
	public function process_item_tax( $item, $submission ) {

		$rates    = getpaid_get_item_tax_rates( $item, $submission->country, $submission->state );
		$rates    = getpaid_filter_item_tax_rates( $item, $rates );
		$taxes    = getpaid_calculate_item_taxes( $item->get_sub_total(), $rates );
		$r_taxes  = getpaid_calculate_item_taxes( $item->get_recurring_sub_total(), $rates );

		foreach ( $taxes as $name => $amount ) {
			$recurring = isset( $r_taxes[ $name ] ) ? $r_taxes[ $name ] : 0;
			$tax       = getpaid_prepare_item_tax( $item, $name, $amount, $recurring );

			if ( ! isset( $this->taxes[ $name ] ) ) {
				$this->taxes[ $name ] = $tax;
				continue;
			}

			$this->taxes[ $name ]['initial_tax']   += $tax['initial_tax'];
			$this->taxes[ $name ]['recurring_tax'] += $tax['recurring_tax'];

		}

	}

	/**
	 * Checks if the submission has a digital item.
	 *
	 * @param GetPaid_Payment_Form_Submission $submission
	 * @since 1.0.19
	 * @return bool
	 */
	public function has_digital_item( $submission ) {

		foreach ( $submission->get_items() as $item ) {

			if ( 'digital' == $item->get_vat_rule() ) {
				return true;
			}

		}

		return false;
	}

	/**
	 * Checks if this is an eu store.
	 *
	 * @since 1.0.19
	 * @return bool
	 */
	public function is_eu_store() {
		return $this->is_eu_country( wpinv_get_default_country() );
	}

	/**
	 * Checks if this is an eu country.
	 *
	 * @param string $country
	 * @since 1.0.19
	 * @return bool
	 */
	public function is_eu_country( $country ) {
		return getpaid_is_eu_state( $country ) || getpaid_is_gst_country( $country );
	}

	/**
	 * Checks if this is an eu purchase.
	 *
	 * @param string $customer_country
	 * @since 1.0.19
	 * @return bool
	 */
	public function is_eu_transaction( $customer_country ) {
		return $this->is_eu_country( $customer_country ) && $this->is_eu_store();
	}

	/**
	 * Retrieves the vat number.
	 *
	 * @param GetPaid_Payment_Form_Submission $submission
	 * @since 1.0.19
	 * @return string
	 */
	public function get_vat_number( $submission ) {

		// Retrieve from the posted number.
		$vat_number = $submission->get_field( 'wpinv_vat_number', 'billing' );
		if ( ! empty( $vat_number ) ) {
			return wpinv_clean( $vat_number );
		}

		// Retrieve from the invoice.
		return $submission->has_invoice() ? $submission->get_invoice()->get_vat_number() : '';
	}

	/**
	 * Retrieves the company.
	 *
	 * @param GetPaid_Payment_Form_Submission $submission
	 * @since 1.0.19
	 * @return string
	 */
	public function get_company( $submission ) {

		// Retrieve from the posted data.
		$company = $submission->get_field( 'wpinv_company', 'billing' );
		if ( ! empty( $company ) ) {
			return wpinv_clean( $company );
		}

		// Retrieve from the invoice.
		return $submission->has_invoice() ? $submission->get_invoice()->get_company() : '';
	}

	/**
	 * Checks if we require a VAT number.
	 *
	 * @param bool $ip_in_eu Whether the customer IP is from the EU
	 * @param bool $country_in_eu Whether the customer country is from the EU
	 * @since 1.0.19
	 * @return string
	 */
	public function requires_vat( $ip_in_eu, $country_in_eu ) {

		$prevent_b2c = wpinv_get_option( 'vat_prevent_b2c_purchase' );
		$prevent_b2c = ! empty( $prevent_b2c );
		$is_eu       = $ip_in_eu || $country_in_eu;

		return $prevent_b2c && $is_eu;
	}

	/**
	 * Validate VAT data.
	 *
	 * @param GetPaid_Payment_Form_Submission $submission
	 * @since 1.0.19
	 */
	public function validate_vat( $submission ) {

		$in_eu = $this->is_eu_transaction( $submission->country );

		// Abort if we are not validating vat numbers.
		if ( ! $in_eu ) {
            return;
		}

		// Prepare variables.
		$vat_number  = $this->get_vat_number( $submission );
		$ip_country  = getpaid_get_ip_country();
        $is_eu       = $this->is_eu_country( $submission->country );
        $is_ip_eu    = $this->is_eu_country( $ip_country );

		// If we're preventing business to consumer purchases,
		if ( $this->requires_vat( $is_ip_eu, $is_eu ) && empty( $vat_number ) ) {

			// Ensure that a vat number has been specified.
			throw new Exception(
				wp_sprintf(
					__( 'Please enter your %s number to verify your purchase is by an EU business.', 'invoicing' ),
					getpaid_vat_name()
				)
			);

		}

		// Abort if we are not validating vat (vat number should exist, user should be in eu and business too).
		if ( ! $in_eu ) {
            return;
		}

		if ( ! wpinv_validate_vat_number( $vat_number, $submission->country ) ) {
			throw new Exception( __( 'Your VAT number is invalid', 'invoicing' ) );
		}

	}

}
