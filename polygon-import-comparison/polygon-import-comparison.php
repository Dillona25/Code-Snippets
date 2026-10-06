<?php
/* Intrepid CSV Polygon Parser */

private static function build_polygon_preview($rows, $location, $locations)
{
    $location_area_cache = [];
    $preview_rows = [];

    foreach ($rows as $index => $row) {
        /* Some markets represent multiple WordPress location so here we determine every location that could potentially own this polygon */
        // Only for Dacono & Frederick
        $candidate_locations = self::get_candidate_locations_for_market(
            $row['market'],
            $locations
        );

        if (empty($candidate_locations)) {
            $candidate_locations = [$location];
        }

        /* Normalize inconsistent external identifiers */
        $da_key = self::normalize_da_key($row['location']);

        if ('' === $da_key) {
            $da_key = self::normalize_da_key($row['da_id']);
        }

        if ('' === $da_key) {
            $preview_rows[] = self::preview_error_row(
                $index,
                $row,
                'Could not determine polygon identifier.'
            );

            continue;
        }

        /* Extract and validate the coordinates before we compare the incoming polygon with WordPress data */
        $pairs = self::extract_coordinate_pairs($row);

        if (is_wp_error($pairs)) {
            $preview_rows[] = self::preview_error_row(
                $index,
                $row,
                $pairs->get_error_message(),
                $da_key
            );

            continue;
        }

        $owner = self::find_da_owner(
            $da_key,
            $candidate_locations,
            $location_area_cache,
            $pairs
        );

        $has_owner_conflict = !empty($owner['conflict']);

        $owner_id = $owner && !$has_owner_conflict
            ? (int) $owner['location']->ID
            : 0;

        $selected_is_owner = $owner_id === (int) $location->ID;

        /* Normalize the business data associated with the incoming polygon */
        $status = self::normalize_status_label($row['status']);
        $color = self::get_color_for_status($status);

        $messaging = self::get_messaging_for_status(
            $status,
            $location->post_title
        );

        $button_settings = self::get_button_settings_for_status($status);
        $bounds_kml = self::coordinate_pairs_to_kml($pairs);

        $action = 'add';
        $reason = 'Missing in WordPress';
        $match_index = null;

        /* This is where we determine whether this polygon should be added, updated, skipped, or flagged */
        if ($has_owner_conflict) {
            $action = 'conflict';
            $reason = 'Polygon found in multiple candidate locations';

        } elseif ($owner && !$selected_is_owner) {
            continue;

        } elseif ($selected_is_owner) {
            $match_index = $owner['match']['index'];
            $existing_row = $owner['match']['row'];

            $existing_kml = (string) (
                $existing_row['bounds_kml'] ?? ''
            );

            $existing_color = strtolower(
                (string) ($existing_row['color'] ?? '')
            );

            /* Polygon equality cannot be determined from the raw coordinate
             strings because the same polygon may start at a different
             vertex or traverse its points in the opposite direction */

            $same_bounds = self::coordinate_rings_equal(
                self::parse_kml_coordinate_pairs($existing_kml),
                $pairs
            );

            $same_color =
                strtolower($color) === $existing_color;

            $same_section_name =
                self::normalize_copy_for_compare(
                    $messaging['section_name']
                ) ===
                self::normalize_copy_for_compare(
                    $existing_row['section_name'] ?? ''
                );

            $same_success_message =
                self::normalize_copy_for_compare(
                    $messaging['success_message']
                ) ===
                self::normalize_copy_for_compare(
                    $existing_row['success_message'] ?? ''
                );

            $same_button_settings = self::button_settings_match(
                $existing_row,
                $button_settings
            );

            if (
                $same_bounds &&
                $same_color &&
                $same_section_name &&
                $same_success_message &&
                $same_button_settings
            ) {
                $action = 'skip';
                $reason = 'Already matches';
            } else {
                $action = 'update';

                $reason = self::get_mismatch_reason(
                    $same_bounds,
                    $same_color,
                    $same_section_name,
                    $same_success_message,
                    $same_button_settings
                );
            }
        }

        /* Store the normalized values that are presented for the confirmation */
        $preview_rows[] = [
            'id'              => $index,
            'action'          => $action,
            'reason'          => $reason,
            'da_key'          => $da_key,
            'pon_name'        => self::format_da_name($da_key),
            'status'          => $status,
            'color'           => $color,
            'section_name'    => $messaging['section_name'],
            'success_message' => $messaging['success_message'],
            'button_settings' => $button_settings,
            'bounds_kml'      => $bounds_kml,
            'match_index'     => $match_index,
        ];
    }

    return $preview_rows;
}


/* Extract longitude/latitude pairs from either the normalized coordinate column or the raw GeoJSON from the CSV */
private static function extract_coordinate_pairs($row)
{
    $coords = json_decode($row['coordinates'], true);

    if (!is_array($coords) && '' !== $row['raw_geometry']) {
        $raw = json_decode($row['raw_geometry'], true);

        if (isset($raw['geometry']['coordinates'])) {
            $coords = $raw['geometry']['coordinates'];
        }
    }

    if (!is_array($coords)) {
        return new WP_Error(
            'polygon_coords_error',
            'Could not parse polygon coordinates.'
        );
    }

    $pairs = self::flatten_coordinate_pairs($coords);

    if (count($pairs) < 3) {
        return new WP_Error(
            'polygon_coords_count_error',
            'Polygon must include at least three coordinate points.'
        );
    }

    return $pairs;
}


/* Flatten nested GeoJSON coordinate arrays into long/lat pairs */
private static function flatten_coordinate_pairs($coords)
{
    if (
        isset($coords[0], $coords[1]) &&
        is_numeric($coords[0]) &&
        is_numeric($coords[1])
    ) {
        return [
            [
                'lng' => (float) $coords[0],
                'lat' => (float) $coords[1],
            ],
        ];
    }

    $pairs = [];

    foreach ($coords as $item) {
        if (!is_array($item)) {
            continue;
        }

        foreach (self::flatten_coordinate_pairs($item) as $pair) {
            $pairs[] = $pair;
        }
    }

    return $pairs;
}


private static function coordinate_pairs_to_kml($pairs)
{
    $parts = [];

    foreach ($pairs as $pair) {
        $parts[] =
            self::format_coordinate_number($pair['lng']) . ',' .
            self::format_coordinate_number($pair['lat']) . ',0';
    }

    return implode(' ', $parts);
}


private static function format_coordinate_number($value)
{
    $formatted = rtrim(
        rtrim(sprintf('%.12F', (float) $value), '0'),
        '.'
    );

    return '-0' === $formatted ? '0' : $formatted;
}


/* Parse the coordinates already in WordPress back into long/lat pairs. */
private static function parse_kml_coordinate_pairs($kml)
{
    preg_match_all(
        '/(-?\d+(?:\.\d+)?),\s*(-?\d+(?:\.\d+)?)(?:,\s*-?\d+(?:\.\d+)?)?/',
        (string) $kml,
        $matches,
        PREG_SET_ORDER
    );

    $pairs = [];

    foreach ($matches as $match) {
        $pairs[] = [
            'lng' => (float) $match[1],
            'lat' => (float) $match[2],
        ];
    }

    return $pairs;
}


/* Compare polygons after canonicalizing their coordinate rings */
private static function coordinate_rings_equal($a, $b)
{
    return self::canonical_coordinate_ring($a)
        === self::canonical_coordinate_ring($b);
}



private static function canonical_coordinate_ring($pairs)
{
    $points = [];

    foreach ($pairs as $pair) {
        if (!isset($pair['lng'], $pair['lat'])) {
            continue;
        }

    
        $lng = (float) self::format_coordinate_number($pair['lng']);
        $lat = (float) self::format_coordinate_number($pair['lat']);

        $points[] =
            round($lng, 7) . ',' .
            round($lat, 7);
    }

   
    if (count($points) > 1 && $points[0] === end($points)) {
        array_pop($points);
    }

    if (empty($points)) {
        return [];
    }


    $forward = self::rotate_ring_to_lowest_point($points);

    $reverse = self::rotate_ring_to_lowest_point(
        array_reverse($points)
    );

    return implode('|', $forward) <= implode('|', $reverse)
        ? $forward
        : $reverse;
}

private static function rotate_ring_to_lowest_point($points)
{
    $lowest_index = 0;

    foreach ($points as $index => $point) {
        if (strcmp($point, $points[$lowest_index]) < 0) {
            $lowest_index = $index;
        }
    }

    return array_merge(
        array_slice($points, $lowest_index),
        array_slice($points, 0, $lowest_index)
    );
}