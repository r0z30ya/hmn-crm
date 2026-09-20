<?php
/** CRM roles and capabilities. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class HMN_CRM_Access implements HMN_CRM_Module_Interface {
	public function __construct() { $this->boot(); }
	public function boot() { add_action( 'init', array( __CLASS__, 'ensure_roles' ), 1 ); }

	/** Create or upgrade the CRM-only roles without granting WordPress administration. */
	public static function ensure_roles() {
		$operator_caps = array(
			'read' => true,
			HMN_CRM_Core::CAP_ACCESS => true,
			HMN_CRM_Core::CAP_MANAGE_APPOINTMENTS => true,
			HMN_CRM_Core::CAP_MANAGE_CUSTOMERS => true,
		);
		self::grant( HMN_CRM_Core::ROLE_OPERATOR, 'اپراتور CRM', $operator_caps );
		self::grant( HMN_CRM_Core::ROLE_MANAGER, 'مدیر CRM', array_merge( $operator_caps, array(
			HMN_CRM_Core::CAP_MANAGE_SCHEDULING => true,
			HMN_CRM_Core::CAP_MANAGE_SETTINGS => true,
		) ) );
	}

	private static function grant( $slug, $label, $caps ) {
		$role = get_role( $slug );
		if ( ! $role ) { $role = add_role( $slug, $label, $caps ); }
		if ( ! $role ) { return; }
		foreach ( $caps as $cap => $enabled ) { if ( $enabled ) { $role->add_cap( $cap ); } }
	}
}
new HMN_CRM_Access();
