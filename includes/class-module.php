<?php
/**
 * Base contract for HMN CRM modules.
 *
 * @package HMN_CRM
 */
interface HMN_CRM_Module_Interface {
	/** Bootstrap module hooks and services. */
	public function boot();
}
