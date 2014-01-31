<?php
/**
 * TribeEventsRecurrenceMeta
 *
 * WordPress hooks and filters controlling event recurrence
 */
class TribeEventsRecurrenceMeta {
	const UPDATE_TYPE_ALL = 1;
	const UPDATE_TYPE_FUTURE = 2;
	const UPDATE_TYPE_SINGLE = 3;
	public static $recurrence_default_meta = array(
		'recType' => null,
		'recEndType' => null,
		'recEnd' => null,
		'recEndCount' => null,
		'recCustomType' => null,
		'recCustomInterval' => null,
		'recCustomTypeText' => null,
		'recCustomRecurrenceDescription' => null,
		'recOccurrenceCountText' => null,
		'recCustomWeekDay' => null,
		'recCustomMonthNumber' => null,
		'recCustomMonthDay' => null,
		'recCustomYearFilter' => null,
		'recCustomYearMonthNumber' => null,
		'recCustomYearMonthDay' => null,
		'recCustomYearMonth' => array()
		);

	/** @var TribeEventsRecurrenceScheduler */
	private static $scheduler = NULL;


	public static function init() {
		add_action( 'tribe_events_update_meta', array( __CLASS__, 'updateRecurrenceMeta' ), 1, 3 );
		add_action( 'tribe_events_date_display', array( __CLASS__, 'loadRecurrenceData' ) );
		add_action(	'wp_trash_post', array( __CLASS__, 'deleteRecurringEvent') );

		add_action(	'wp_insert_post_data', array( __CLASS__, 'maybeBreakFromSeries' ), 100, 2 );
		add_action( 'admin_notices', array( __CLASS__, 'showRecurrenceErrorFlash') );
		add_action( 'tribe_recurring_event_error', array( __CLASS__, 'setupRecurrenceErrorMsg'), 10, 2 );

    //add_filter( 'tribe_get_event_link', array( __CLASS__, 'addDateToEventPermalink'), 10, 2 );
    add_filter( 'post_row_actions', array( __CLASS__, 'removeQuickEdit'), 10, 2 );
    add_action( 'wp_before_admin_bar_render', array( __CLASS__, 'admin_bar_render'));

    	add_filter( 'tribe_events_query_posts_groupby', array( __CLASS__, 'addGroupBy' ), 10, 2 );

		add_filter( 'tribe_settings_tab_fields', array( __CLASS__, 'inject_settings' ), 10, 2 );

		add_action( 'load-edit.php', array( __CLASS__, 'combineRecurringRequestIds' ) );

		self::reset_scheduler();
	}

	public static function admin_bar_render(){
		global $post, $wp_admin_bar;
		if( !is_admin() &&  tribe_is_recurring_event( $post )) {
			$edit_link = $wp_admin_bar->get_node('edit');
			// becuase on some pages we actually don't have the edit option
			if( !empty($edit_link->href)) {
				$edit_link->href = $edit_link->href . '&eventDate=' . TribeDateUtils::dateOnly($post->EventStartDate);
				$wp_admin_bar->remove_menu('edit');
				$wp_admin_bar->add_node($edit_link);
			}
		}
	}

   public static function removeQuickEdit( $actions, $post ) {
      if( tribe_is_recurring_event( $post ) ) {
         unset($actions['inline hide-if-no-js']);
      }

      return $actions;
   }

	/**
	 * Update event recurrence when a recurring event is saved
	 * @param integer $event_id id of the event to update
	 * @param array $data data defining the recurrence of this event
	 * @return void
	 */
	public static function updateRecurrenceMeta($event_id, $data) {
		// save recurrence
		if ( isset($data['recurrence_action']) && $data['recurrence_action'] != self::UPDATE_TYPE_ALL ) {
			$recurrence_meta = NULL;
		} elseif ( isset($data['recurrence']) ){
			$recurrence_meta = $data['recurrence'];
			// for an update when the event start/end dates change
			$recurrence_meta['EventStartDate'] = $data['EventStartDate'];
			$recurrence_meta['EventEndDate'] = $data['EventEndDate'];
		} else {
			$recurrence_meta = null;
		}

		if( TribeEventsRecurrenceMeta::isRecurrenceValid( $event_id, $recurrence_meta ) ) {
			$updated = update_post_meta($event_id, '_EventRecurrence', $recurrence_meta);
			TribeEventsRecurrenceMeta::saveEvents($event_id, $updated);
		}
	}

	/**
	 * Displays the events recurrence form on the event editor screen
	 * @param integer $postId ID of the current event
	 * @return void
	 */
	public static function loadRecurrenceData($postId) {
		// convert array to variables that can be used in the view
		extract(TribeEventsRecurrenceMeta::getRecurrenceMeta($postId));

		$premium = TribeEventsPro::instance();
		include( TribeEventsPro::instance()->pluginPath . 'admin-views/event-recurrence.php' );
	}

	/**
	 * Deletes a SINGLE occurrence of a recurring event
	 * @param integer $postId ID of the event that may have an occurence deleted from it
	 * @return void
	 */
	public static function deleteRecurringEvent($postId) {
		if (isset($_REQUEST['event_start']) && !isset($_REQUEST['deleteAll'])) {
			$occurrenceDate = $_REQUEST['event_start'];
		}else{
			$occurrenceDate = null;
		}

		if( $occurrenceDate ) {
			self::removeOccurrence( $postId, $occurrenceDate );
			wp_safe_redirect( add_query_arg( 'post_type', TribeEvents::POSTTYPE, admin_url( 'edit.php' ) ) );
			exit();
		}
	}

	public static function filter_passthrough( $data ){
		return $data;
	}

	/**
	 * Handles updating recurring events.
	 * @param array $post_object_data Data stored in the posts table
	 * @param array $submitted_data All data submitted to wp_insert_post()
	 * @return array The $post_object_data, or an empty array if we're overriding
	 */
	public static function maybeBreakFromSeries( $post_object_data, $submitted_data ) {
		if ( empty($submitted_data['ID']) ) {
			return $post_object_data; // can't break out from a new post
		}
		if ( empty($submitted_data['recurrence_action']) ) {
			return $post_object_data; // no signal to break out
		}
		$postId = $submitted_data['ID'];

		$is_first_in_series = ($post_object_data['post_parent'] == 0);

		$breaker = new TribeEvents_RecurrenceSeriesBreaker();

		if ( $submitted_data['recurrence_action'] == TribeEventsRecurrenceMeta::UPDATE_TYPE_FUTURE ) {
			if ( $is_first_in_series ) {
				// can't break an entire series off of itself
			} else {
				$post_object_data['post_parent'] = 0;
				$breaker->break_remaining_events_from_series( $postId );
			}
		} elseif ( $submitted_data['recurrence_action'] == TribeEventsRecurrenceMeta::UPDATE_TYPE_SINGLE ) {
			if ( $is_first_in_series ) {
				$breaker->break_first_event_from_series( $postId );
			} else {
				$post_object_data['post_parent'] = 0;
				$breaker->break_single_event_from_series( $postId );
			}
		} elseif ( !$is_first_in_series ) {
			// We don't want child events to diverge. Update the parent event.
			$post_object_data['ID'] = $post_object_data['post_parent'];
			$post_object_data['post_parent'] = 0;
		}
		return $post_object_data;
	}


	/**
	 * Setup an error message if there is a problem with a given recurrence.
	 * The message is saved in an option to survive the page load and is deleted after being displayed
 	 * @param array $event The event object that is being saved
	 * @param array $msg The message to display
	 * @return void
	 */
	public static function setupRecurrenceErrorMsg( $event_id, $msg ) {
		global $current_screen;

		// only do this when editing events
		if( is_admin() && $current_screen->id == TribeEvents::POSTTYPE ) {
			update_post_meta($event_id, 'tribe_flash_message', $msg);
		}
	}


	/**
	 * Display an error message if there is a problem with a given recurrence and clear it from the cache
	 * @return void
	 */
	public static function showRecurrenceErrorFlash(){
		global $post, $current_screen;

		if ( $current_screen->base == 'post' && $current_screen->post_type == TribeEvents::POSTTYPE ) {
			$msg = get_post_meta($post->ID, 'tribe_flash_message', true);

			if ($msg) {
				echo '<div class="error"><p>Recurrence not saved: ' . $msg . '</p></div>';
			   delete_post_meta($post->ID, 'tribe_flash_message');
		   }
	   }
	}

	/**
	 * Convenience method for turning event meta into keys available to turn into PHP variables
	 * @param integer $postId ID of the event being updated
	 * @param $recurrenceData array The actual recurrence data
	 * @return array
	 */
	public static function getRecurrenceMeta( $postId, $recurrenceData = null ) {
		if (!$recurrenceData )
			$recurrenceData = get_post_meta($postId, '_EventRecurrence', true);

		$recurrenceData = self::recurrenceMetaDefault( $recurrenceData );

		$recurrence_meta = array();

		if ( $recurrenceData ) {
			$recurrence_meta['recType'] = $recurrenceData['type'];
			$recurrence_meta['recEndType'] = $recurrenceData['end-type'];
			$recurrence_meta['recEnd'] = $recurrenceData['end'];
			$recurrence_meta['recEndCount'] = $recurrenceData['end-count'];
			$recurrence_meta['recCustomType'] = $recurrenceData['custom-type'];
			$recurrence_meta['recCustomInterval'] = $recurrenceData['custom-interval'];
			$recurrence_meta['recCustomTypeText'] = $recurrenceData['custom-type-text'];
			$recurrence_meta['recOccurrenceCountText'] = $recurrenceData['occurrence-count-text'];
			$recurrence_meta['recCustomRecurrenceDescription'] = $recurrenceData['recurrence-description'];
			$recurrence_meta['recCustomWeekDay'] = $recurrenceData['custom-week-day'];
			$recurrence_meta['recCustomMonthNumber'] = $recurrenceData['custom-month-number'];
			$recurrence_meta['recCustomMonthDay'] = $recurrenceData['custom-month-day'];
			$recurrence_meta['recCustomYearMonth'] = $recurrenceData['custom-year-month'];
			$recurrence_meta['recCustomYearFilter'] = $recurrenceData['custom-year-filter'];
			$recurrence_meta['recCustomYearMonthNumber'] = $recurrenceData['custom-year-month-number'];
			$recurrence_meta['recCustomYearMonthDay'] = $recurrenceData['custom-year-month-day'];
		}

		$recurrence_meta = wp_parse_args( $recurrence_meta, self::$recurrence_default_meta );

		return apply_filters( 'TribeEventsRecurrenceMeta_getRecurrenceMeta', $recurrence_meta );
	}

	/**
	 * Clean up meta array by providing defaults.
	 *
	 * @param  array  $meta
	 * @return array of $meta merged with defaults
	 */
	protected static function recurrenceMetaDefault( $meta = array() ){
		$default_meta = array(
			'type' => null,
			'end-type' => null,
			'end' => null,
			'end-count' => null,
			'custom-type' => null,
			'custom-interval' => null,
			'custom-type-text' => null,
			'occurrence-count-text' => null,
			'recurrence-description' => null,
			'custom-week-day' => null,
			'custom-month-number' => null,
			'custom-month-day' => null,
			'custom-year-month' => array(),
			'custom-year-filter' => null,
			'custom-year-month-number' => null,
			'custom-year-month-day' => null );
		$meta = wp_parse_args( (array) $meta, $default_meta );
		return $meta;
	}


	/**
	 * Deletes a single occurrence of an event
 	 * @param integer $postId ID of the event that occurrence will be deleted from
	 * @param string $date date of occurrence to delete
	 * @return void
	 */
	private static function removeOccurrence( $postId, $date ) {
		// TODO: get the post for the instance and delete it
		$startDate = TribeEvents::get_series_start_date($postId);
		$date = TribeDateUtils::addTimeToDate( $date, TribeDateUtils::timeOnly($startDate) );

		delete_post_meta( $postId, '_EventStartDate', $date );
	}

	/**
	 * Removes all occurrences of an event that are after a given date
 	 * @param integer $postId ID of the event that occurrences will be deleted from
	 * @param string $date date to delete occurrences after, current date if not specified
	 * @return void
	 */
	private static function removeFutureOccurrences( $postId, $date = null ) {
		$date = $date ? strtotime($date) : time();

		$occurrences = tribe_get_recurrence_start_dates($postId);

		foreach($occurrences as $occurrence) {
			if (strtotime(TribeDateUtils::dateOnly($occurrence)) >= $date ) {
				delete_post_meta($postId, '_EventStartDate', $occurrence);
			}
		}
	}

	/**
	 * Removes all occurrences of an event that are before a given date
 	 * @param integer $postId ID of the event that occurrences will be deleted from
	 * @param string $date date to delete occurrences before, current date if not specified
	 * @return void
	 */
	private static function removePastOccurrences( $postId, $date = null ) {
		$date = $date ? strtotime($date) : time();
		$occurrences = tribe_get_recurrence_start_dates($postId);

		foreach($occurrences as $occurrence) {
			if (strtotime(TribeDateUtils::dateOnly($occurrence)) < $date ) {
				delete_post_meta($postId, '_EventStartDate', $occurrence);
			}
		}
	}


	/**
	 * Adjust the end date of a series to be the start date of the last instance after a given date.  This function is used
	 * when a series is split into two and the original series needs to be shortened to the start date of the new series
 	 * @param integer $postId ID of the event that will have it's series end adjusted
	 * @param string $date new end date for the series
	 * @return the number of occurrences in the shortened series.  This is useful if you need to know how many occurrences
	 * the new series should have
	 */
	private static function adjustRecurrenceEnd( $postId, $date = null ) {
		$date = $date ? strtotime($date) : time();

		$occurrences = tribe_get_recurrence_start_dates($postId);
		$occurrenceCount = 0;
		sort($occurrences);

		if( is_array($occurrences) && sizeof($occurrences) > 0 ) {
			$prev = $occurrences[0];
		}

		foreach($occurrences as $occurrence) {
			$occurrenceCount++; // keep track of how many we are keeping
			if (strtotime(TribeDateUtils::dateOnly($occurrence)) > $date ) {
				$recurrenceMeta = get_post_meta($postId, '_EventRecurrence', true);
				$recurrenceMeta['end'] = date(DateSeriesRules::DATE_ONLY_FORMAT, strtotime($prev));

				update_post_meta($postId, '_EventRecurrence', $recurrenceMeta);
				break;
			}

			$prev = $occurrence;
		}

		// useful for knowing how many occurrences are needed for new series
		return $occurrenceCount;
	}

	/**
	 * Change the EventEndDate of a recurring event.  This is needed when a recurring series is split into two series.  The new series
	 * has to have its end date adjusted.  Note:  EventEndDate is only set once per recurring event and is the end date of the first occurrence.
	 * Subsequent occurrences have a calculated event date based on duration of the first event (EventEndDate - EventStartDate)
 	 * @param integer $postId ID of the event that will have it's series end date adjusted
	 * @return void
	 */
	private static function adjustEndDate( $postId ) {
		$occurrences = tribe_get_recurrence_start_dates($postId);
		sort($occurrences);

		$duration = get_post_meta($postId, '_EventDuration', true);

		if( is_array($occurrences) && sizeof($occurrences) > 0 ) {
			update_post_meta($postId, '_EventEndDate', date(DateSeriesRules::DATE_FORMAT, strtotime($occurrences[0]) + $duration));
		}
	}

	/**
	 * Clone an event when splitting up a recurring series
 	 * @param array $data The event information for the original event
	 * @return int|WP_Error
	 */
	private static function cloneEvent( $data ) {
      $old_id = $data['ID'];

		$data['ID'] = null;
		$new_event = wp_insert_post($data);
      self::cloneEventAttachments( $old_id, $new_event );

		return $new_event;
	}

	private static function cloneEventAttachments( $old_event, $new_event ) {
		// Update the post thumbnail.
		if ( has_post_thumbnail( $old_event ) ) {
    		$thumbnail_id = get_post_thumbnail_id( $old_event );
    		update_post_meta( $new_event, '_thumbnail_id', $thumbnail_id );
		}
	}

	/**
	 * Recurrence validation method.  This is checked after saving an event, but before splitting a series out into multiple occurrences
 	 * @param int $event_id The event object that is being saved
	 * @param array $recurrence_meta Recurrence information for this event
	 * @return bool
	 */
	public static function isRecurrenceValid( $event_id, $recurrence_meta  ) {
		extract(TribeEventsRecurrenceMeta::getRecurrenceMeta( $event_id, $recurrence_meta ));
		$valid = true;
		$errorMsg = '';

		if($recType == "Custom" && $recCustomType == "Monthly" && ($recCustomMonthDay == '-' || $recCustomMonthNumber == '')) {
			$valid = false;
			$errorMsg = __('Monthly custom recurrences cannot have a dash set as the day to occur on.', 'tribe-events-calendar-pro');
		} else if($recType == "Custom" && $recCustomType == "Yearly" && $recCustomYearMonthDay == '-') {
			$valid = false;
			$errorMsg = __('Yearly custom recurrences cannot have a dash set as the day to occur on.', 'tribe-events-calendar-pro');
		}

		if ( !$valid ) {
			do_action( 'tribe_recurring_event_error', $event_id, $errorMsg );
		}

		return $valid;
	}

	/**
	 * Do the actual work of saving a recurring series of events
	 * @param int $postId The event that is being saved
	 * @param bool $updated
	 * @return void
	 */
	public static function saveEvents( $postId ) {

		$existing_instances = get_posts(array(
			'post_parent' => $postId,
			'post_type' => TribeEvents::POSTTYPE,
			'posts_per_page' => -1,
			'fields' => 'ids',
			'post_status' => 'any',
			'meta_key' => '_EventStartDate',
			'orderby' => 'meta_key',
			'order' => 'ASC',
		));

		$recurrence = self::getRecurrenceForEvent($postId);

		if ( $recurrence ) {
			$recurrence->setMinDate(strtotime(self::$scheduler->get_earliest_date()));
			$recurrence->setMaxDate(strtotime(self::$scheduler->get_latest_date()));
			$dates = (array) $recurrence->getDates();
			$to_update = array();

			if ( $recurrence->constrainedByMaxDate() !== FALSE ) {
				update_post_meta($postId, '_EventNextPendingRecurrence', date(DateSeriesRules::DATE_FORMAT, $recurrence->constrainedByMaxDate()));
			}

			foreach ( $existing_instances as $instance ) {
				$start_date = strtotime( get_post_meta($instance, '_EventStartDate', true) );
				$found = array_search( $start_date, $dates );
				if ( $found === FALSE ) {
					do_action( 'tribe_events_deleting_child_post', $instance, $start_date );
					wp_delete_post( $instance, TRUE );
				} else {
					$to_update[$instance] = $dates[$found];
					unset($dates[$found]); // so we don't re-add it
				}
			}

			foreach($dates as $date) {
				$instance = new Tribe_Events_Recurrence_Instance( $postId, $date );
				$instance->save();
			}
			foreach ( $to_update as $instance_id => $date ) {
				$instance = new Tribe_Events_Recurrence_Instance( $postId, $date, $instance_id );
				$instance->save();
			}
		}
	}

	public static function save_pending_events( $event_id ) {
		$next_pending = get_post_meta( $event_id, '_EventNextPendingRecurrence', TRUE );
		if ( empty($next_pending) ) {
			return;
		}

		$recurrence = self::getRecurrenceForEvent($event_id);
		$recurrence->setMinDate(strtotime($next_pending));
		$recurrence->setMaxDate(strtotime(self::$scheduler->get_latest_date()));
		$dates = (array) $recurrence->getDates();

		if ( empty($dates) ) {
			return; // nothing to add right now. try again later
		}

		delete_post_meta($event_id, '_EventNextPendingRecurrence');
		if ( $recurrence->constrainedByMaxDate() !== FALSE ) {
			update_post_meta($event_id, '_EventNextPendingRecurrence', date(DateSeriesRules::DATE_FORMAT, $recurrence->constrainedByMaxDate()));
		}

		foreach($dates as $date) {
			$instance = new Tribe_Events_Recurrence_Instance( $event_id, $date );
			$instance->save();
		}

	}

	private static function getRecurrenceForEvent( $event_id ) {
		/** @var string $recType */
		/** @var string $recEndType */
		/** @var string $recEnd */
		/** @var int $recEndCount */
		extract(TribeEventsRecurrenceMeta::getRecurrenceMeta($event_id));
		if ( $recType == 'None' ) {
			return NULL;
		}
		$rules = TribeEventsRecurrenceMeta::getSeriesRules($event_id);

		// use the recurrence start meta if necessary because we can't guarantee which order the start date will come back in
		$recStart = strtotime(self::get_series_start_date($event_id));

		switch( $recEndType ) {
			case 'On':
				$recEnd = strtotime(TribeDateUtils::endOfDay($recEnd));
				break;
			case 'Never':
				$recEnd = TribeRecurrence::NO_END;
				break;
			case 'After':
			default:
				$recEnd = $recEndCount - 1; // subtract one because event is first occurrence
				break;
		}

		$recurrence = new TribeRecurrence($recStart, $recEnd, $rules, $recEndType == "After", get_post( $event_id ) );
		return $recurrence;
	}

	/**
	 * Decide which rule set to use for finding all the dates in an event series
 	 * @param array $postId The event to find the series for
	 * @return DateSeriesRules
	 */
	public static function getSeriesRules($postId) {
		extract(TribeEventsRecurrenceMeta::getRecurrenceMeta($postId));
		$rules = null;

		if(!$recCustomInterval)
			$recCustomInterval = 1;

		if($recType == "Every Day" || ($recType == "Custom" && $recCustomType == "Daily")) {
			$rules = new DaySeriesRules($recType == "Every Day" ? 1 : $recCustomInterval);
		} else if($recType == "Every Week") {
			$rules = new WeekSeriesRules(1);
		} else if ($recType == "Custom" && $recCustomType == "Weekly") {
			$rules = new WeekSeriesRules($recCustomInterval ? $recCustomInterval : 1, $recCustomWeekDay);
		} else if($recType == "Every Month") {
			$rules = new MonthSeriesRules(1);
		} else if($recType == "Custom" && $recCustomType == "Monthly") {
			$recCustomMonthDayOfMonth = is_numeric($recCustomMonthNumber) ? array($recCustomMonthNumber) : null;
			$recCustomMonthNumber = self::ordinalToInt($recCustomMonthNumber);
			$rules = new MonthSeriesRules($recCustomInterval ? $recCustomInterval : 1, $recCustomMonthDayOfMonth, $recCustomMonthNumber, $recCustomMonthDay);
		} else if($recType == "Every Year") {
			$rules = new YearSeriesRules(1);
		} else if($recType == "Custom" && $recCustomType == "Yearly") {
			$rules = new YearSeriesRules($recCustomInterval ? $recCustomInterval : 1, $recCustomYearMonth, $recCustomYearFilter ? $recCustomYearMonthNumber : null, $recCustomYearFilter ? $recCustomYearMonthDay : null);
		}

		return $rules;
	}

	/**
	 * Get the recurrence pattern in text format by post id.
	 *
	 * @param int $postId The post id.
	 * @return sting The human readable string.
	 */
	public static function recurrenceToTextByPost( $postId = null ) {
		if( $postId == null ) {
			global $post;
			$postId = $post->ID;
		}

		$recurrence_rules = TribeEventsRecurrenceMeta::getRecurrenceMeta($postId);
		$start_date = TribeEvents::get_series_start_date( $postId );

		$output_text = empty( $recurrence_rules['recCustomRecurrenceDescription'] ) ? self::recurrenceToText( $recurrence_rules, $start_date ) : $recurrence_rules['recCustomRecurrenceDescription'];

		return $output_text;
	}

	/**
	 * Convert the event recurrence meta into a human readable string
 	 * @param array $postId The recurring event
	 * @return The human readable string
	 */
	public static function recurrenceToText( $recurrence_rules = array(), $start_date ) {
		$text = "";
		$custom_text = "";
		$occurrence_text = "";
		$recType = '';
		$recEndCount = '';
		$recCustomType = '';
		$recCustomInterval = null;
		$recCustomMonthNumber = null;
		$recCustomYearMonthNumber = null;
		$recCustomYearFilter = '';
		$recCustomYearMonth = '';
		$recCustomYearMonthDay = '';
		extract( $recurrence_rules );

		if ($recType == "Every Day") {
			$text = __("Every day", 'tribe-events-calendar-pro');
			$occurrence_text = sprintf(_n(" for %d day", " for %d days", $recEndCount, 'tribe-events-calendar-pro'), $recEndCount);
			$custom_text = "";
		} else if($recType == "Every Week") {
			$text = __("Every week", 'tribe-events-calendar-pro');
			$occurrence_text = sprintf(_n(" for %d week", " for %d weeks", $recEndCount, 'tribe-events-calendar-pro'), $recEndCount);
		} else if($recType == "Every Month") {
			$text = __("Every month", 'tribe-events-calendar-pro');
			$occurrence_text = sprintf(_n(" for %d month", " for %d months", $recEndCount, 'tribe-events-calendar-pro'), $recEndCount);
		} else if($recType == "Every Year") {
			$text = __("Every year", 'tribe-events-calendar-pro');
			$occurrence_text = sprintf(_n(" for %d year", " for %d years", $recEndCount, 'tribe-events-calendar-pro'), $recEndCount);
		} else if ($recType == "Custom") {
			if ($recCustomType == "Daily") {
				$text = $recCustomInterval == 1 ?
					__("Every day", 'tribe-events-calendar-pro') :
					sprintf(__("Every %d days", 'tribe-events-calendar-pro'), $recCustomInterval);
				$occurrence_text = sprintf(_n(", recurring %d time", ", recurring %d times", $recEndCount, 'tribe-events-calendar-pro'), $recEndCount);
			} else if ($recCustomType == "Weekly") {
				$text = $recCustomInterval == 1 ?
					__("Every week", 'tribe-events-calendar-pro') :
					sprintf(__("Every %d weeks", 'tribe-events-calendar-pro'), $recCustomInterval);
				$custom_text = sprintf(__(" on %s", 'tribe-events-calendar-pro'), self::daysToText($recCustomWeekDay));
				$occurrence_text = sprintf(_n(", recurring %d time", ", recurring %d times", $recEndCount, 'tribe-events-calendar-pro'), $recEndCount);
			} else if ($recCustomType == "Monthly") {
				$text = $recCustomInterval == 1 ?
					__("Every month", 'tribe-events-calendar-pro') :
					sprintf(__("Every %d months", 'tribe-events-calendar-pro'), $recCustomInterval);
               $number_display = is_numeric($recCustomMonthNumber) ? TribeDateUtils::numberToOrdinal( $recCustomMonthNumber ) : strtolower($recCustomMonthNumber);
				$custom_text = sprintf(__(" on the %s %s", 'tribe-events-calendar-pro'), $number_display,  is_numeric($recCustomMonthNumber) ? __("day", 'tribe-events-calendar-pro') : self::daysToText($recCustomMonthDay));
				$occurrence_text = sprintf(_n(", recurring %d time", ", recurring %d times", $recEndCount, 'tribe-events-calendar-pro'), $recEndCount);
			} else if ($recCustomType == "Yearly") {
				$text = $recCustomInterval == 1 ?
					__("Every year", 'tribe-events-calendar-pro') :
					sprintf(__("Every %d years", 'tribe-events-calendar-pro'), $recCustomInterval);

				$customYearNumber = $recCustomYearMonthNumber != -1 ? TribeDateUtils::numberToOrdinal($recCustomYearMonthNumber) : __("last", 'tribe-events-calendar-pro');

				$day = $recCustomYearFilter ? $customYearNumber : TribeDateUtils::numberToOrdinal( date('j', strtotime( $start_date ) ) );
				$of_week = $recCustomYearFilter ? self::daysToText($recCustomYearMonthDay) : "";
				$months = self::monthsToText($recCustomYearMonth);
				$custom_text = sprintf(__(" on the %s %s of %s", 'tribe-events-calendar-pro'), $day, $of_week, $months);
				$occurrence_text = sprintf(_n(", recurring %d time", ", recurring %d times", $recEndCount, 'tribe-events-calendar-pro'), $recEndCount);
			}
		}

		// end text
		if ( $recEndType == "On" ) {
			$endText = ' '.sprintf(__(" until %s", 'tribe-events-calendar-pro'), date_i18n(get_option('date_format'), strtotime($recEnd))) ;
		} else {
			$endText = $occurrence_text;
		}

		return sprintf(__('%s%s%s', 'tribe-events-calendar-pro'), $text, $custom_text, $endText);
	}

	/**
	 * Convert an array of day ids into a human readable string
 	 * @param array $days The day ids
	 * @return The human readable string
	 */
	private static function daysToText($days) {
		$day_words = array(__("Monday", 'tribe-events-calendar-pro'), __("Tuesday", 'tribe-events-calendar-pro'), __("Wednesday", 'tribe-events-calendar-pro'), __("Thursday", 'tribe-events-calendar-pro'), __("Friday", 'tribe-events-calendar-pro'), __("Saturday", 'tribe-events-calendar-pro'), __("Sunday", 'tribe-events-calendar-pro'));
		$count = sizeof($days);
		$day_text = "";

		for($i = 0; $i < $count ; $i++) {
			if ( $count > 2 && $i == $count - 1 ) {
				$day_text .= __(", and", 'tribe-events-calendar-pro').' ';
			} else if ($count == 2 && $i == $count - 1) {
				$day_text .= ' '.__("and", 'tribe-events-calendar-pro').' ';
			} else if ($count > 2 && $i > 0) {
				$day_text .= __(",", 'tribe-events-calendar-pro').' ';
			}

			$day_text .= $day_words[$days[$i]-1] ? $day_words[$days[$i]-1] : "day";
		}

		return $day_text;
	}

	/**
	 * Convert an array of month ids into a human readable string
 	 * @param array $months The month ids
	 * @return The human readable string
	 */
	private static function monthsToText($months) {
		$month_words = array(__("January", 'tribe-events-calendar-pro'), __("February", 'tribe-events-calendar-pro'), __("March", 'tribe-events-calendar-pro'), __("April", 'tribe-events-calendar-pro'),
			 __("May", 'tribe-events-calendar-pro'), __("June", 'tribe-events-calendar-pro'), __("July", 'tribe-events-calendar-pro'), __("August", 'tribe-events-calendar-pro'), __("September", 'tribe-events-calendar-pro'), __("October", 'tribe-events-calendar-pro'), __("November", 'tribe-events-calendar-pro'), __("December", 'tribe-events-calendar-pro'));
		$count = sizeof($months);
		$month_text = "";

		for($i = 0; $i < $count ; $i++) {
			if ( $count > 2 && $i == $count - 1 ) {
				$month_text .= __(", and ", 'tribe-events-calendar-pro');
			} else if ($count == 2 && $i == $count - 1) {
				$month_text .= __(" and ", 'tribe-events-calendar-pro');
			} else if ($count > 2 && $i > 0) {
				$month_text .= __(", ", 'tribe-events-calendar-pro');
			}

			$month_text .= $month_words[$months[$i]-1];
		}

		return $month_text;
	}

	/**
	 * Convert an ordinal from an ECP recurrence series into an integer
 	 * @param string $ordinal The ordinal number
	 * @return An integer representation of the ordinal
	 */
	private static function ordinalToInt($ordinal) {
		switch( $ordinal ) {
			case "First": return 1;
			case "Second": return 2;
			case "Third": return 3;
			case "Fourth": return 4;
			case "Fifth": return 5;
			case "Last": return -1;
		   default: return null;
		}
	}

	/**
	 * Adds the Group By that hides future occurences of recurring events if setting is set to.
	 *
	 * @since 3.0
	 * @author PaulHughes
	 *
	 * @param string $group_by The current group by clause.
	 * @param $query
	 * @return string The new group by clause.
	 */
	public static function addGroupBy( $group_by, $query ) {
		if ( isset( $query->query_vars['tribeHideRecurrence'] ) && $query->query_vars['tribeHideRecurrence'] == 1 ) {
			$group_by .= ' ID';
		}
		return $group_by;
	}

	/**
	 * Adds setting for hiding subsequent occurrences by default.
	 *
	 * @since 3.0
	 * @author PaulHughes
	 *
	 * @param array $args
	 * @param string $id
	 *
	 * @return array
	 */
	public static function inject_settings( $args, $id ) {

		if ( $id == 'general' ) {

			// we want to inject the hiding subsequent occurrences into the general section directly after "Live update AJAX"
			$args = TribeEvents::array_insert_after_key( 'liveFiltersUpdate', $args, array(
				'hideSubsequentRecurrencesDefault' => array(
					'type' => 'checkbox_bool',
					'label' => __( 'Recurring event instances', 'tribe-events-calendar-pro' ),
					'tooltip' => __( 'Show only the first instance of each recurring event (only affects list-style views).', 'tribe-events-calendar-pro' ),
					'default' => false,
					'validation_type' => 'boolean',
				),
 				'userToggleSubsequentRecurrences' => array(
					'type' => 'checkbox_bool',
					'label' => __( 'Front-end recurring event instances toggle', 'tribe-events-calendar-pro' ),
					'tooltip' => __( 'Allow users to decide whether to show all instances of a recurring event.', 'tribe-events-calendar-pro' ),
					'default' => false,
					'validation_type' => 'boolean',
				),
				'recurrenceMaxMonthsBefore' => array(
					'type' => 'text',
					'size' => 'small',
					'label' => __('Clean up recurring events after', 'tribe-events-calendar-pro'),
					'tooltip' => __( 'Automatically remove recurring event instances older than this', 'tribe-events-calendar-pro'),
					'validation_type' => 'positive_int',
					'default' => 24,
				),
				'recurrenceMaxMonthsAfter' => array(
					'type' => 'text',
					'size' => 'small',
					'label' => __('Create recurring events in advance for', 'tribe-events-calendar-pro' ),
					'tooltip' => __( 'Recurring events will be created this far in advance', 'tribe-events-calendar-pro'),
					'validation_type' => 'positive_int',
					'default' => 24,
				),
			) );
			add_filter( 'tribe_field_div_end', array( __CLASS__, 'add_months_to_settings_field' ), 100, 2 );

		}


		return $args;
	}

	/**
	 * @param string $html
	 * @param TribeField $field
	 *
	 * @return string
	 */
	public static function add_months_to_settings_field( $html, $field ) {
		if ( in_array($field->name, array('recurrenceMaxMonthsBefore', 'recurrenceMaxMonthsAfter')) ) {
			$html = __(' months', 'tribe-events-calendar-pro').$html;
		}
		return $html;
	}

	/**
	 * Combines the ['post'] piece of the $_REQUEST variable so it only has unique post ids.
	 *
	 * @since 3.0
	 * @author Paul Hughes
	 *
	 * @return void
	 */
	public static function combineRecurringRequestIds() {
		if ( isset( $_REQUEST['post_type'] ) && $_REQUEST['post_type'] == TribeEvents::POSTTYPE && !empty( $_REQUEST['post'] ) && is_array( $_REQUEST['post'] ) ) {
			$_REQUEST['post'] = array_unique( $_REQUEST['post'] );
		}
	}

	/**
	 * @return void
	 */
	public static function reset_scheduler() {
		require_once('tribe-recurrence-scheduler.php');
		if ( !empty(self::$scheduler) ) {
			self::$scheduler->remove_hooks();
		}
		self::$scheduler = new TribeEventsRecurrenceScheduler( tribe_get_option('recurrenceMaxMonthsBefore', 24), tribe_get_option('recurrenceMaxMonthsAfter', 24));
		self::$scheduler->add_hooks();
	}

	/**
	 * @return TribeEventsRecurrenceScheduler
	 */
	public static function get_scheduler() {
		if ( empty(self::$scheduler) ) {
			self::reset_scheduler();
		}
		return self::$scheduler;
	}

	/**
	 * Placed here for compatibility reasons. This can be removed
	 * when Events Calendar 3.2 or greater is released
	 *
	 * @todo Remove this method
	 * @param int $post_id
	 * @return string
	 * @see TribeEvents::get_series_start_date()
	 */
	private static function get_series_start_date( $post_id ) {
		if ( method_exists('TribeEvents', 'get_series_start_date') ) {
			return TribeEvents::get_series_start_date($post_id);
		}
		$start_dates = tribe_get_recurrence_start_dates($post_id);
		return reset($start_dates);
	}
}
