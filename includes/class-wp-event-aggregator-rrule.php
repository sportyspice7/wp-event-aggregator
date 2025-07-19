<?php
/**
 * Recurring event handling using RRULE.
 *
 * @package WP_Event_Aggregator
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WP_Event_Aggregator_RRule {

    public function __construct() {
        add_filter( 'rwmb_meta_boxes', array( $this, 'register_meta_boxes' ) );
        add_action( 'save_post', array( $this, 'save_occurrences' ), 20, 2 );
    }

    public function register_meta_boxes( $meta_boxes ) {
        if ( ! post_type_exists( 'wp_events' ) ) {
            return $meta_boxes;
        }

        $meta_boxes[] = array(
            'id'         => 'wpea-rrule',
            'title'      => __( 'Recurrence', 'wp-event-aggregator' ),
            'post_types' => array( 'wp_events' ),
            'fields'     => array(
                array(
                    'id'   => 'wpea_rrule',
                    'name' => __( 'RRULE', 'wp-event-aggregator' ),
                    'type' => 'text',
                    'desc' => __( 'iCal RRULE string (e.g. FREQ=MONTHLY;BYDAY=3TU,3WE;UNTIL=2024-12-31)', 'wp-event-aggregator' ),
                ),
            ),
        );

        return $meta_boxes;
    }

    public function save_occurrences( $post_id, $post ) {
        if ( $post->post_type !== 'wp_events' ) {
            return;
        }
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        $rrule = get_post_meta( $post_id, 'wpea_rrule', true );
        if ( empty( $rrule ) ) {
            return;
        }

        $start_date  = get_post_meta( $post_id, 'event_start_date', true );
        $start_hour  = get_post_meta( $post_id, 'event_start_hour', true );
        $start_min   = get_post_meta( $post_id, 'event_start_minute', true );
        $start_mer   = get_post_meta( $post_id, 'event_start_meridian', true );

        if ( ! $start_date ) {
            return;
        }

        $time           = sprintf( '%02d:%02d %s', $start_hour, $start_min, $start_mer );
        $start_datetime = date( 'Y-m-d H:i:s', strtotime( $start_date . ' ' . $time ) );

        list( $occurrences, $final ) = $this->calculate_occurrences( $start_datetime, $rrule, 6 );

        update_post_meta( $post_id, 'wpea_next_date', isset( $occurrences[0] ) ? $occurrences[0] : '' );
        for ( $i = 0; $i < 5; $i++ ) {
            update_post_meta( $post_id, 'wpea_recurring_' . ( $i + 1 ), isset( $occurrences[ $i ] ) ? $occurrences[ $i ] : '' );
        }
        update_post_meta( $post_id, 'wpea_final_date', $final );
    }

    private function calculate_occurrences( $start, $rrule, $max = 6 ) {
        $rule      = $this->parse_rrule( $rrule );
        $start_dt  = new DateTime( $start );
        $freq      = isset( $rule['FREQ'] ) ? $rule['FREQ'] : 'DAILY';
        $interval  = isset( $rule['INTERVAL'] ) ? max( 1, intval( $rule['INTERVAL'] ) ) : 1;
        $count     = isset( $rule['COUNT'] ) ? intval( $rule['COUNT'] ) : null;
        $until     = isset( $rule['UNTIL'] ) ? new DateTime( $rule['UNTIL'] ) : null;
        $byday_raw = isset( $rule['BYDAY'] ) ? explode( ',', $rule['BYDAY'] ) : array();

        $occurrences = array();
        $final       = null;
        $generated   = 0;

        switch ( $freq ) {
            case 'DAILY':
                $current = clone $start_dt;
                while ( true ) {
                    if ( $current >= $start_dt ) {
                        if ( count( $occurrences ) < $max ) {
                            $occurrences[] = $current->format( 'Y-m-d H:i:s' );
                        }
                        $final = $current->format( 'Y-m-d H:i:s' );
                        $generated++;
                        if ( ( $count && $generated >= $count ) || ( $until && $current >= $until ) ) {
                            break;
                        }
                    }
                    $current->modify( '+' . $interval . ' day' );
                    if ( $generated >= $max && ! $count && ! $until ) {
                        break;
                    }
                }
                break;

            case 'WEEKLY':
                $days = $this->parse_byday( $byday_raw );
                if ( empty( $days ) ) {
                    $days[] = array( 'day' => strtoupper( $start_dt->format( 'D' ) ), 'nth' => null );
                }
                $week_start = clone $start_dt;
                $week_start->modify( 'monday this week' );

                while ( true ) {
                    foreach ( $days as $d ) {
                        $candidate = clone $week_start;
                        $candidate->modify( $this->weekday_to_string( $d['day'] ) );
                        $candidate->setTime( (int) $start_dt->format( 'H' ), (int) $start_dt->format( 'i' ), (int) $start_dt->format( 's' ) );
                        if ( $candidate < $start_dt ) {
                            continue;
                        }
                        if ( $until && $candidate > $until ) {
                            break 2;
                        }
                        if ( count( $occurrences ) < $max ) {
                            $occurrences[] = $candidate->format( 'Y-m-d H:i:s' );
                        }
                        $final = $candidate->format( 'Y-m-d H:i:s' );
                        $generated++;
                        if ( ( $count && $generated >= $count ) ) {
                            break 3;
                        }
                    }
                    $week_start->modify( '+' . $interval . ' week' );
                    if ( $generated >= $max && ! $count && ! $until ) {
                        break;
                    }
                }
                break;

            case 'MONTHLY':
                $day_tokens = $this->parse_byday( $byday_raw );
                $month_iter = clone $start_dt;
                $month_iter->modify( 'first day of this month' );
                while ( true ) {
                    $y = $month_iter->format( 'Y' );
                    $m = $month_iter->format( 'm' );
                    if ( $day_tokens ) {
                        foreach ( $day_tokens as $t ) {
                            $dates = array();
                            if ( $t['nth'] ) {
                                $dates[] = $this->nth_weekday_of_month( $y, $m, $t['day'], $t['nth'], $start_dt->format( 'H:i:s' ) );
                            } else {
                                $dates   = $this->weekdays_in_month( $y, $m, $t['day'], $start_dt->format( 'H:i:s' ) );
                            }
                            foreach ( $dates as $candidate ) {
                                if ( $candidate < $start_dt ) {
                                    continue;
                                }
                                if ( $until && $candidate > $until ) {
                                    break 4;
                                }
                                if ( count( $occurrences ) < $max ) {
                                    $occurrences[] = $candidate->format( 'Y-m-d H:i:s' );
                                }
                                $final = $candidate->format( 'Y-m-d H:i:s' );
                                $generated++;
                                if ( $count && $generated >= $count ) {
                                    break 4;
                                }
                            }
                        }
                    } else {
                        $day      = $start_dt->format( 'd' );
                        $candidate = new DateTime( "$y-$m-$day " . $start_dt->format( 'H:i:s' ) );
                        if ( $candidate >= $start_dt ) {
                            if ( $until && $candidate > $until ) {
                                break;
                            }
                            if ( count( $occurrences ) < $max ) {
                                $occurrences[] = $candidate->format( 'Y-m-d H:i:s' );
                            }
                            $final = $candidate->format( 'Y-m-d H:i:s' );
                            $generated++;
                            if ( $count && $generated >= $count ) {
                                break;
                            }
                        }
                    }
                    $month_iter->modify( '+' . $interval . ' month' );
                    if ( ( $generated >= $max && ! $count && ! $until ) ) {
                        break;
                    }
                }
                break;
        }

        return array( $occurrences, $final );
    }

    private function parse_rrule( $rrule ) {
        $out  = array();
        $rrule = strtoupper( trim( $rrule ) );
        foreach ( explode( ';', $rrule ) as $part ) {
            if ( empty( $part ) ) {
                continue;
            }
            $bits = explode( '=', $part, 2 );
            if ( count( $bits ) === 2 ) {
                $out[ trim( $bits[0] ) ] = trim( $bits[1] );
            }
        }
        return $out;
    }

    private function parse_byday( $tokens ) {
        $out = array();
        foreach ( $tokens as $token ) {
            if ( preg_match( '/^([+-]?\d)?(SU|MO|TU|WE|TH|FR|SA)$/', $token, $m ) ) {
                $out[] = array(
                    'nth' => isset( $m[1] ) && $m[1] !== '' ? intval( $m[1] ) : null,
                    'day' => $m[2],
                );
            }
        }
        return $out;
    }

    private function weekday_to_string( $day ) {
        $map = array(
            'SU' => 'sunday',
            'MO' => 'monday',
            'TU' => 'tuesday',
            'WE' => 'wednesday',
            'TH' => 'thursday',
            'FR' => 'friday',
            'SA' => 'saturday',
        );
        return isset( $map[ $day ] ) ? $map[ $day ] : 'monday';
    }

    private function nth_weekday_of_month( $year, $month, $weekday, $nth, $time ) {
        $weekday_str = $this->weekday_to_string( $weekday );
        $date        = new DateTime( "first $weekday_str of $year-$month" );
        if ( $date->format( 'n' ) != intval( $month ) ) {
            $date->modify( 'next ' . $weekday_str );
        }
        if ( $nth > 1 ) {
            $date->modify( '+' . ( $nth - 1 ) . ' week' );
        }
        list( $h, $m, $s ) = explode( ':', $time );
        $date->setTime( $h, $m, $s );
        return $date;
    }

    private function weekdays_in_month( $year, $month, $weekday, $time ) {
        $dates       = array();
        $weekday_str = $this->weekday_to_string( $weekday );
        $date        = new DateTime( "first $weekday_str of $year-$month" );
        if ( $date->format( 'n' ) != intval( $month ) ) {
            $date->modify( 'next ' . $weekday_str );
        }
        while ( $date->format( 'n' ) == intval( $month ) ) {
            list( $h, $m, $s ) = explode( ':', $time );
            $date->setTime( $h, $m, $s );
            $dates[] = clone $date;
            $date->modify( '+1 week' );
        }
        return $dates;
    }
}
