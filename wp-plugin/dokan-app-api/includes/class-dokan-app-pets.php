<?php
/**
 * Pets stored in user meta `_dokan_app_pets` (array keyed by id).
 *
 * @package Dokan_App_API
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Dokan_App_Pets {

	const META     = '_dokan_app_pets';
	const MAX_PETS = 20;

	/**
	 * Allowed kinds.
	 *
	 * @return string[]
	 */
	public static function kinds() {
		return array( 'cat', 'dog', 'bird', 'fish', 'small' );
	}

	/**
	 * Allowed food types.
	 *
	 * @return string[]
	 */
	public static function food_types() {
		return array( 'dry', 'wet', 'mixed' );
	}

	/**
	 * Raw stored pets for a user.
	 *
	 * @param int $user_id User.
	 * @return array id => pet
	 */
	public static function all( $user_id ) {
		$pets = get_user_meta( $user_id, self::META, true );
		return is_array( $pets ) ? $pets : array();
	}

	/**
	 * List as array of shapes.
	 *
	 * @param int $user_id User.
	 * @return array
	 */
	public static function list_shapes( $user_id ) {
		$out = array();
		foreach ( self::all( $user_id ) as $id => $pet ) {
			$out[] = self::shape( $id, $pet );
		}
		return $out;
	}

	/**
	 * Get one pet.
	 *
	 * @param int    $user_id User.
	 * @param string $id      Pet id.
	 * @return array|null
	 */
	public static function get( $user_id, $id ) {
		$pets = self::all( $user_id );
		return isset( $pets[ $id ] ) ? self::shape( $id, $pets[ $id ] ) : null;
	}

	/**
	 * Normalise to the contract shape.
	 *
	 * @param string $id  Id.
	 * @param array  $pet Stored.
	 * @return array
	 */
	public static function shape( $id, $pet ) {
		$pet = is_array( $pet ) ? $pet : array();
		return array(
			'id'         => (string) $id,
			'name'       => isset( $pet['name'] ) ? (string) $pet['name'] : '',
			'kind'       => isset( $pet['kind'] ) && in_array( $pet['kind'], self::kinds(), true ) ? $pet['kind'] : 'cat',
			'breed'      => isset( $pet['breed'] ) ? (string) $pet['breed'] : '',
			'birth_date' => ! empty( $pet['birth_date'] ) ? (string) $pet['birth_date'] : null,
			'weight_kg'  => isset( $pet['weight_kg'] ) && null !== $pet['weight_kg'] ? (float) $pet['weight_kg'] : null,
			'food_type'  => isset( $pet['food_type'] ) && in_array( $pet['food_type'], self::food_types(), true ) ? $pet['food_type'] : null,
			'photo'      => ! empty( $pet['photo'] ) ? (string) $pet['photo'] : null,
			'remind'     => isset( $pet['remind'] ) ? (bool) $pet['remind'] : true,
		);
	}

	/**
	 * Validate & sanitise input fields.
	 *
	 * @param array $in      Input.
	 * @param bool  $partial Partial update.
	 * @return array|WP_Error Clean fields.
	 */
	public static function validate( $in, $partial ) {
		$clean = array();
		if ( ! is_array( $in ) ) {
			$in = array();
		}

		if ( array_key_exists( 'name', $in ) || ! $partial ) {
			$name = isset( $in['name'] ) && is_scalar( $in['name'] ) ? trim( sanitize_text_field( (string) $in['name'] ) ) : '';
			if ( '' === $name ) {
				return Dokan_App_Helpers::error( 'dokan_app_pet_name', 'يرجى إدخال اسم الحيوان الأليف.', 400 );
			}
			$clean['name'] = function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 60 ) : substr( $name, 0, 120 );
		}

		if ( array_key_exists( 'kind', $in ) || ! $partial ) {
			$kind = isset( $in['kind'] ) && is_scalar( $in['kind'] ) ? sanitize_key( (string) $in['kind'] ) : '';
			if ( ! in_array( $kind, self::kinds(), true ) ) {
				return Dokan_App_Helpers::error( 'dokan_app_pet_kind', 'نوع الحيوان غير صحيح.', 400 );
			}
			$clean['kind'] = $kind;
		}

		if ( array_key_exists( 'breed', $in ) ) {
			$breed          = is_scalar( $in['breed'] ) ? trim( sanitize_text_field( (string) $in['breed'] ) ) : '';
			$clean['breed'] = function_exists( 'mb_substr' ) ? mb_substr( $breed, 0, 80 ) : substr( $breed, 0, 160 );
		} elseif ( ! $partial ) {
			$clean['breed'] = '';
		}

		if ( array_key_exists( 'birth_date', $in ) ) {
			$bd = $in['birth_date'];
			if ( null === $bd || '' === $bd ) {
				$clean['birth_date'] = null;
			} else {
				$bd = is_scalar( $bd ) ? (string) $bd : '';
				$dt = DateTime::createFromFormat( '!Y-m-d', $bd );
				if ( ! $dt || $dt->format( 'Y-m-d' ) !== $bd || $dt->getTimestamp() > time() + DAY_IN_SECONDS || (int) $dt->format( 'Y' ) < 1980 ) {
					return Dokan_App_Helpers::error( 'dokan_app_pet_birth', 'تاريخ الميلاد غير صحيح.', 400 );
				}
				$clean['birth_date'] = $bd;
			}
		} elseif ( ! $partial ) {
			$clean['birth_date'] = null;
		}

		if ( array_key_exists( 'weight_kg', $in ) ) {
			$w = $in['weight_kg'];
			if ( null === $w || '' === $w ) {
				$clean['weight_kg'] = null;
			} elseif ( is_numeric( $w ) && (float) $w > 0 && (float) $w <= 200 ) {
				$clean['weight_kg'] = round( (float) $w, 2 );
			} else {
				return Dokan_App_Helpers::error( 'dokan_app_pet_weight', 'الوزن غير صحيح.', 400 );
			}
		} elseif ( ! $partial ) {
			$clean['weight_kg'] = null;
		}

		if ( array_key_exists( 'food_type', $in ) ) {
			$f = $in['food_type'];
			if ( null === $f || '' === $f ) {
				$clean['food_type'] = null;
			} elseif ( is_scalar( $f ) && in_array( (string) $f, self::food_types(), true ) ) {
				$clean['food_type'] = (string) $f;
			} else {
				return Dokan_App_Helpers::error( 'dokan_app_pet_food', 'نوع الطعام غير صحيح.', 400 );
			}
		} elseif ( ! $partial ) {
			$clean['food_type'] = null;
		}

		if ( array_key_exists( 'photo', $in ) ) {
			$p = $in['photo'];
			if ( null === $p || '' === $p ) {
				$clean['photo'] = null;
			} else {
				$url = is_scalar( $p ) ? esc_url_raw( (string) $p, array( 'https', 'http' ) ) : '';
				if ( '' === $url || strlen( $url ) > 1000 ) {
					return Dokan_App_Helpers::error( 'dokan_app_pet_photo', 'رابط الصورة غير صحيح.', 400 );
				}
				$clean['photo'] = $url;
			}
		} elseif ( ! $partial ) {
			$clean['photo'] = null;
		}

		if ( array_key_exists( 'remind', $in ) ) {
			$clean['remind'] = rest_sanitize_boolean( $in['remind'] );
		} elseif ( ! $partial ) {
			$clean['remind'] = true;
		}

		return $clean;
	}

	/**
	 * Short unique id.
	 *
	 * @return string
	 */
	private static function new_id() {
		return substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 12 );
	}

	/**
	 * Create.
	 *
	 * @param int   $user_id User.
	 * @param array $in      Input.
	 * @return array|WP_Error
	 */
	public static function create( $user_id, $in ) {
		$pets = self::all( $user_id );
		if ( count( $pets ) >= self::MAX_PETS ) {
			return Dokan_App_Helpers::error( 'dokan_app_pet_limit', 'وصلت للحد الأقصى من الحيوانات الأليفة.', 400 );
		}
		$clean = self::validate( $in, false );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}
		do {
			$id = self::new_id();
		} while ( isset( $pets[ $id ] ) );
		$clean['created'] = time();
		$pets[ $id ]      = $clean;
		update_user_meta( $user_id, self::META, $pets );
		return self::shape( $id, $clean );
	}

	/**
	 * Update.
	 *
	 * @param int    $user_id User.
	 * @param string $id      Id.
	 * @param array  $in      Input.
	 * @return array|WP_Error
	 */
	public static function update( $user_id, $id, $in ) {
		$pets = self::all( $user_id );
		if ( ! isset( $pets[ $id ] ) ) {
			return Dokan_App_Helpers::error( 'dokan_app_pet_not_found', 'الحيوان الأليف غير موجود.', 404 );
		}
		$clean = self::validate( $in, true );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}
		$pets[ $id ] = array_merge( (array) $pets[ $id ], $clean );
		update_user_meta( $user_id, self::META, $pets );
		return self::shape( $id, $pets[ $id ] );
	}

	/**
	 * Delete.
	 *
	 * @param int    $user_id User.
	 * @param string $id      Id.
	 * @return true|WP_Error
	 */
	public static function delete( $user_id, $id ) {
		$pets = self::all( $user_id );
		if ( ! isset( $pets[ $id ] ) ) {
			return Dokan_App_Helpers::error( 'dokan_app_pet_not_found', 'الحيوان الأليف غير موجود.', 404 );
		}
		unset( $pets[ $id ] );
		update_user_meta( $user_id, self::META, $pets );
		return true;
	}

	/**
	 * Sanitize a pet id from a route.
	 *
	 * @param string $id Raw.
	 * @return string
	 */
	public static function clean_id( $id ) {
		return substr( preg_replace( '/[^a-zA-Z0-9]/', '', (string) $id ), 0, 36 );
	}
}
