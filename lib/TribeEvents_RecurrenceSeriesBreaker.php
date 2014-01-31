<?php

/**
 * Class TribeEvents_RecurrenceSeriesBreaker
 */
class TribeEvents_RecurrenceSeriesBreaker {
	/**
	 * @param int $first_event_of_new_series The post ID of the first event of the new series
	 * @return void
	 */
	public function break_remaining_events_from_series( $first_event_of_new_series ) {
		$post = get_post($first_event_of_new_series);
		$parent_id = $post->post_parent;
		if ( empty($parent_id) ) {
			return;
		}
		$children = get_posts(array(
			'post_type' => TribeEvents::POSTTYPE,
			'post_parent' => $parent_id,
			'post_status' => 'any',
			'meta_key' => '_EventStartDate',
			'orderby' => 'meta_key',
			'order' => 'ASC',
			'fields' => 'ids',
		));

		$children_to_move_to_new_series = array();
		$break_date = get_post_meta( $first_event_of_new_series, '_EventStartDate', TRUE );
		foreach ( $children as $child_id ) {
			$child_date = get_post_meta( $child_id, '_EventStartDate', TRUE );
			if ( $child_date > $break_date ) {
				$children_to_move_to_new_series[] = $child_id;
			}
		}

		$this->copy_post_meta( $parent_id, $first_event_of_new_series );

		$parent_recurrence = get_post_meta( $parent_id, '_EventRecurrence', TRUE );
		$new_recurrence = get_post_meta( $first_event_of_new_series, '_EventRecurrence', TRUE );

		if ( $parent_recurrence['end-type'] == 'After' ) {
			$parent_recurrence['end-count'] -= (count($children_to_move_to_new_series) + 1);
			$new_recurrence['end-count'] = ( count($children_to_move_to_new_series) + 1 );
		} else {
			$parent_recurrence['end'] = date('Y-m-d', strtotime($break_date));
		}

		update_post_meta( $parent_id, '_EventRecurrence', $parent_recurrence );
		update_post_meta( $first_event_of_new_series, '_EventRecurrence', $new_recurrence );
		add_post_meta( $first_event_of_new_series, '_EventOriginalParent', $parent_id );

		if ( ( count($children) - count($children_to_move_to_new_series) ) == 1 ) {
			delete_post_meta( $parent_id, '_EventRecurrence' );
		}

		$new_parent = get_post( $first_event_of_new_series );
		$new_parent->post_parent = 0;
		wp_update_post($new_parent);
		foreach ( $children_to_move_to_new_series as $child_id ) {
			$child = get_post($child_id);
			$child->post_parent = $first_event_of_new_series;
			wp_update_post($child);
		}
	}

	/**
	 * @param int $first_event_id The post ID of the first event in the series
	 * @return void
	 */
	public function break_first_event_from_series( $first_event_id ) {
		$children = get_posts(array(
			'post_type' => TribeEvents::POSTTYPE,
			'post_parent' => $first_event_id,
			'post_status' => 'any',
			'meta_key' => '_EventStartDate',
			'orderby' => 'meta_key',
			'order' => 'ASC',
			'fields' => 'ids',
		));

		if ( empty($children) ) {
			delete_post_meta( $first_event_id, '_EventRecurrence' );
			return;
		}

		$first_child = get_post(reset($children));

		$this->copy_post_meta( $first_event_id, $first_child->ID );
		delete_post_meta( $first_event_id, '_EventRecurrence' );
		add_post_meta( $first_child->ID, '_EventOriginalParent', $first_event_id );

		$new_series_recurrence = get_post_meta( $first_child->ID, '_EventRecurrence', TRUE );

		if ( $new_series_recurrence['end-type'] == 'After' ) {
			$new_series_recurrence['end-count']--;
		}

		update_post_meta( $first_child->ID, '_EventRecurrence', $new_series_recurrence );

		foreach ( $children as $child_id ) {
			$child = get_post($child_id);
			if ( $child_id == $first_child->ID ) {
				$child->post_parent = 0;
			} else {
				$child->post_parent = $first_child->ID;
			}
			wp_update_post($child);
		}
	}

	/**
	 * @param int $event_to_break_out The ID of the event to break out of the series
	 * @return void
	 */
	public function break_single_event_from_series( $event_to_break_out ) {
		$post = get_post($event_to_break_out);
		$parent_id = $post->post_parent;
		if ( empty($parent_id) ) {
			$this->break_first_event_from_series( $event_to_break_out );
			return;
		}

		$this->copy_post_meta( $parent_id, $event_to_break_out );
		delete_post_meta( $event_to_break_out, '_EventRecurrence' );
		add_post_meta( $event_to_break_out, '_EventOriginalParent', $parent_id );

		$parent_recurrence = get_post_meta( $parent_id, '_EventRecurrence', TRUE );
		$parent_recurrence['excluded-dates'][] = date('Y-m-d', strtotime(get_post_meta( $event_to_break_out, '_EventStartDate', TRUE )));

		if ( $parent_recurrence['end-type'] == 'After' ) {
			$parent_recurrence['end-count']--;
		}

		update_post_meta( $parent_id, '_EventRecurrence', $parent_recurrence );

		$post->post_parent = 0;
		wp_update_post($post);
	}

	private function copy_post_meta( $original_post, $destination_post ) {
		$this->clear_post_meta( $destination_post );
		$post_meta_keys = get_post_custom_keys( $original_post );
		if (empty($post_meta_keys)) return;
		$meta_blacklist = $this->get_meta_key_blacklist();
		$meta_keys = array_diff($post_meta_keys, $meta_blacklist);

		foreach ($meta_keys as $meta_key) {
			$meta_values = get_post_custom_values($meta_key, $original_post);
			foreach ($meta_values as $meta_value) {
				$meta_value = maybe_unserialize($meta_value);
				add_post_meta($destination_post, $meta_key, $meta_value);
			}
		}
	}

	private function clear_post_meta( $post_id ) {
		$post_meta_keys = get_post_custom_keys( $post_id );
		$blacklist = $this->get_meta_key_blacklist();
		$post_meta_keys = array_diff( $post_meta_keys, $blacklist );
		foreach ( $post_meta_keys as $key ) {
			delete_post_meta( $post_id, $key );
		}
	}

	private function get_meta_key_blacklist() {
		return array(
			'_edit_lock',
			'_edit_last',
			'_EventStartDate',
			'_EventEndDate',
			'_EventDuration',
		);
	}
}
 