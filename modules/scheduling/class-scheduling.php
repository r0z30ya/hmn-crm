<?php


defined( 'ABSPATH' ) || exit;













class HMN_CRM_Scheduling {
	private static function settings() {
		// ... existing code ...
	}







	private static function defaults() {
		// ... existing code ...
	}






	private static function minutes( $time ) {
		// ... existing code ...
	}













	private static function daily_limit_reached( $date, $daily_limit ) {
		// ... existing code ...
	}

















	private static function exception_matches( $exception, $date, $service_id, $provider_id ) {
		// ... existing code ...
	}
	public static function filter_slots($slots, $date, $service_id = 0, $provider_id = 0) {
		if (!is_array($slots) || !preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', (string)$date)) {
			return array();
		}
		$s = self::settings(); $tz = new DateTimeZone('Asia/Tehran');
		$day = DateTimeImmutable::createFromFormat('!Y-m-d', $date, $tz);
		if (!$day) {
			return array();
		}
		$now = new DateTimeImmutable('now', $tz);
		$today = $now->setTime(0, 0);
		if ($day < $today || $day > $today->modify('+' . absint($s['max_future_days'] ?? 30) . ' days')) {
			return array();
		}



















		$defaults = self::defaults(); $index = ((int)$day->format('w') + 1) % 7; // Saturday is index 0.
		$plan = wp_parse_args(is_array($s['plan'][$index] ?? null) ? $s['plan'][$index] : array(), $defaults['plan'][$index]);
		if (empty($plan['enabled'])) {
			return array();
		}
		if (self::daily_limit_reached($date, absint($s['daily_limit'] ?? 0))) {
			return array();
		}
		$start = $plan['start']; $end = $plan['end']; $blocked = array();



		foreach ((array)($s['exceptions'] ?? array()) as $exception) {
			if (!self::exception_matches($exception, $date, $service_id, $provider_id)) {
				continue;
			}
			$type = $exception['type'] ?? '';




			if (($type === 'holiday' || $type === 'leave') && empty($s['allow_holidays'])) {
				return array();
			}

			if ($type === 'special_hours') {
				if (!empty($exception['start_time'])) {
					$start = $exception['start_time'];
				}
				if (!empty($exception['end_time'])) {
					$end = $exception['end_time'];
				}
			}
			if ($type === 'blocked') {
				$blocked[] = array($exception['start_time'] ?? '', $exception['end_time'] ?? '');
			}
		}






		$start_minutes = self::minutes($start); $end_minutes = self::minutes($end);
		if (null === $start_minutes || null === $end_minutes || $start_minutes >= $end_minutes) {
			return array();
		}
		$break_start = self::minutes($plan['break_start'] ?? ''); $break_end = self::minutes($plan['break_end'] ?? '');
		$interval = max(1, absint($s['slot_step'] ?? 15) + absint($s['buffer_minutes'] ?? 0));
		$minimum = $now->modify('+' . absint($s['min_notice_hours'] ?? 0) . ' hours');
		$result = array();






		foreach ($slots as $slot) {
			$slot = sanitize_text_field((string)$slot); $minutes = self::minutes($slot);
			if (null === $minutes || $minutes < $start_minutes || $minutes >= $end_minutes || 0 !== ($minutes - $start_minutes) % $interval) {
				continue;
			}
			if (null !== $break_start && null !== $break_end && $minutes >= $break_start && $minutes < $break_end) {
				continue;
			}
			$starts_at = DateTimeImmutable::createFromFormat('Y-m-d H:i', $date . ' ' . $slot, $tz);
			if (!$starts_at || $starts_at < $minimum) {
				continue;
			}
			$denied = false;















































































			foreach ($blocked as $range) {
				$from = self::minutes($range[0]); $until = self::minutes($range[1]);
				if (null === $from || null === $until || ($minutes >= $from && $minutes < $until)) {
					$denied = true;
					break;
				}




			}
			if (!$denied) {
				$result[] = $slot;
			}
		}

		return array_values(array_unique($result));
	}





	public static function render() {
		// ... existing code ...
	}



	private static function exception_row( $index, $row, $providers, $services ) {
		// ... existing code ...
	}


















































}

new HMN_CRM_Scheduling();

