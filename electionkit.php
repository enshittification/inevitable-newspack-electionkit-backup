<?php
/**
 * @package NewsPack Election Kit
 * @version 1.0.0
 */
/*
 * Plugin Name: NewsPack Election Kit
 * Plugin URI: https://willenglishiv.com/newspack-election-kit
 * Description: Provides a sample ballot for NewsPack Customers.  Install on the page of your choice with the shortcode [sample_ballot]
 * Author: Will English IV
 * Author URI: https://willenglishiv.com/
 * Version: 1.0.0
 */


add_shortcode('sample_ballot', 'np_sample_ballot_form');
function np_sample_ballot_form($atts) {
	$a = shortcode_atts(array(
		'show_bios' => 'false',
		'debug_location' => ''
	), $atts);
	ob_start(); ?>

	<div class="newspack-electionkit">
		<form class="address-form">
			<input type="hidden" id="ea-show-bio" name="ea-show-bio" value="<?php echo $a['show_bios']; ?>">
			<label for="ek-address">Enter your address where you're registered to vote:</label>
			<span>
				<input type="text" id="ek-address" name="ea-address" value="<?php echo $a['debug_location']; ?>" required>
				<input type="submit" value="Submit">
			</span>
		</form>
		<div class="spinner"><img src="<?php echo plugin_dir_url( __FILE__ ) . "img/25.gif"; ?>"></div>
		<div class="ek-error">There was an error with the sample ballot tool.  Please try again.</div>
		<div class="sample-ballot"></div>
	</div>

	<?php
	return ob_get_clean();
}

add_action('wp_ajax_sample_ballot', 'np_sample_ballot');
add_action('wp_ajax_nopriv_sample_ballot', 'np_sample_ballot');
function np_sample_ballot(){
	$election_date = "2020-11-03";
	$googleApiKey = "AIzaSyAc3rE7u30SEGhbDo8qVoV-k-UaV0hgVI4";
	$googleMapsApiUrl = 'https://maps.googleapis.com/maps/api/geocode/json';
	$bpSampleBallotElections = "https://api4.ballotpedia.org/sample_ballot_elections";
	$bpSampleBallotResults = "https://api4.ballotpedia.org/myvote_results";
	$response = array();

//	if (wp_verify_nonce( $_REQUEST['nonce'], 'electionkit-address' )) {
		$address = $_REQUEST['address'];

		$googleComposeURL = $googleMapsApiUrl . '?' . http_build_query(array(
			'address' => $address,
			'key' => $googleApiKey
		));

		$googleRequest = wp_remote_get($googleComposeURL);
		$googleData = "";

		if( is_wp_error($googleRequest) ) {
			wp_send_json_error("google didn't work");
		} else {
			$googleData = json_decode(wp_remote_retrieve_body($googleRequest));
		}

		if ($googleData->status !== "OK") {
			wp_send_json_error(array(
				"message"=>"google didn't return any data"
			));
		}

		// country check
		$locationResult = $googleData->results[0];

		$in_the_united_states = false;
//		$found_country = false;
//		$long_name_array = [];
		foreach( $locationResult->address_components as $component) {
//			$long_name_array[] = $component;
			if ( $component->long_name == "United States") {
				$in_the_united_states = true;
			}
		}

		if (!$in_the_united_states) {
			wp_send_json_error(array(
				"message"=>"address did not return a valid US location",
				"locationResult" => $locationResult,
			));
		}

//		$response['google_data'] = $googleData;

		$bpComposeURL = $bpSampleBallotElections . '?' . http_build_query(array(
			'lat' => $googleData->results[0]->geometry->location->lat,
			'long' => $googleData->results[0]->geometry->location->lng
		));

		$bpDistrictsRequest = wp_remote_get($bpComposeURL);
		$bpDistrictData = "";
		$bpDistrictArray = [];

		if ( is_wp_error($bpDistrictsRequest) ) {
			wp_send_json_error(array("message"=>"ballotpedia sample ballot elections call didn't work"));
		} else {
			$bpDistrictData = json_decode(wp_remote_retrieve_body($bpDistrictsRequest));
		}

		if (!$bpDistrictData) {
			wp_send_json_error(array("message"=>"ballotpedia sample ballot elections didn't return any data"));
		}

		foreach ($bpDistrictData->data->districts as $district) {
			$bpDistrictArray[] = $district->id;
		}

		$bpComposeURL = $bpSampleBallotResults . '?' . http_build_query(array(
			'districts' => implode(",", $bpDistrictArray),
			'election_date' => $election_date
		));

		//$response['sample_ballot_url'] = $bpComposeURL;

		$bpBallotRequest = wp_remote_get($bpComposeURL);
		$bpBallotData = "";

		if ( is_wp_error($bpBallotRequest) ) {
			wp_send_json_error(array(
				"message" =>"ballotpedia sample ballot results call didn't work",
				"information" => $bpBallotRequest
			));
		} else {
			$bpBallotData = json_decode(wp_remote_retrieve_body($bpBallotRequest));
		}

		if (!$bpBallotData) {
			wp_send_json_error(array("message"=>"ballotpedia sample ballot results didn't return any data"));
		}

		$district_order = array(
			"Country",
			"Congress",
			"State",
			"State Legislative (Upper)",
			"State Legislative (Lower)",
			"County",
			"County subdivision",
			"City",
			"City-town subdivision",
			"Judicial District",
			"Judicial district subdivision",
			"Special District",
			"School District",
		);

		$districts = $bpBallotData->data->districts;

		usort($districts, function ($a, $b) use ($district_order) {
			return array_search($a->type, $district_order) - array_search($b->type, $district_order);
		});

		$district_types = [];

		$ballot_measures = [];

		foreach ($districts as $district) {
			if ($district->ballot_measures) {
				foreach ($district->ballot_measures as $ballot_measure) {
					$ballot_measures[] = $ballot_measure;
				}
			}
		}

		$response['ballot_measures'] = $ballot_measures;

		ob_start();

		if ($ballot_measures) { ?>
			<div class="district">
				<h2 class="district-type">Ballot Measures</h2>
				<ul class="measures">
					<?php foreach($ballot_measures as $ballot_measure) { ?>
						<li class="measure-name">
							<a href="<?php echo $ballot_measure->url; ?>" target="_blank">
								<?php echo $ballot_measure->name; ?>
							</a>
						</li>
					<?php } // ballot measure foreach ?>
				</ul>
			</div>
		<?php } // if/else ballot_measures check

		foreach($districts as $district) {
			$district_types[] = $district->type;
			if ($district->races) { ?>
				<div class="district">
					<h2 class="district-type"><?php echo $district->type . ' - ' . $district->name; ?></h2>
					<?php foreach($district->races as $race) {
						if ($race->candidates) { ?>
							<div class="race">
								<h3 class="race-name">
									<?php echo $race->office->name; ?>
									<?php if ($race->office_position) {
										echo ' <em>(' . $race->office_position . ')</em>';
									} ?>
								</h3>
								<div class="candidates">
									<?php foreach($race->candidates as $candidate) { ?>
										<div class="candidate">
											<div class="candidate-image">
												<?php if ( $candidate->person->image ) { ?>
													<img src="<?php echo $candidate->person->image->thumbnail; ?>">
												<?php } else { ?>
													<img src="<?php echo plugin_dir_url( __FILE__ ) . "img/person-placeholder.jpg"; ?>">
												<?php } ?>
											</div>
											<div class="candidate-content">
												<h4 class="candidate-name">
													<a href="<?php echo $candidate->person->url ?>" target="_blank">
														<?php echo $candidate->person->name; ?>
														<?php if ($candidate->is_incumbent) echo "<em>Incumbent</em>"; ?>
													</a>
												</h4>
												<div class="candidate-party">
													<?php foreach($candidate->party_affiliation as $party) { ?>
														<?php echo $party->name; ?>
													<?php } ?>
												</div>
												<?php if ( $_REQUEST['show_bios'] === 'true' ) { ?>
													<div class="candidate-summary">
														<?php echo $candidate->person->summary; ?>
													</div>
												<?php } ?>
												<div class="social">
													<?php if ($candidate->person->contact_facebook) { ?>
														<a href="<?php echo $candidate->person->contact_facebook ?>" target="_blank" class="icon-facebook">
															<svg viewBox="0 0 24 24" version="1.1" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink">
																<path d="M12 2C6.5 2 2 6.5 2 12c0 5 3.7 9.1 8.4 9.9v-7H7.9V12h2.5V9.8c0-2.5 1.5-3.9 3.8-3.9 1.1 0 2.2.2 2.2.2v2.5h-1.3c-1.2 0-1.6.8-1.6 1.6V12h2.8l-.4 2.9h-2.3v7C18.3 21.1 22 17 22 12c0-5.5-4.5-10-10-10z"></path>
															</svg>
															<span class="screen-reader-text">Facebook</span>
														</a>
													<?php } ?>
													<?php if ($candidate->person->contact_twitter) { ?>
														<a href="<?php echo $candidate->person->contact_twitter ?>" target="_blank" class="icon-twitter">
															<svg viewBox="0 0 24 24" version="1.1" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink">
																<path d="M22.23,5.924c-0.736,0.326-1.527,0.547-2.357,0.646c0.847-0.508,1.498-1.312,1.804-2.27 c-0.793,0.47-1.671,0.812-2.606,0.996C18.324,4.498,17.257,4,16.077,4c-2.266,0-4.103,1.837-4.103,4.103 c0,0.322,0.036,0.635,0.106,0.935C8.67,8.867,5.647,7.234,3.623,4.751C3.27,5.357,3.067,6.062,3.067,6.814 c0,1.424,0.724,2.679,1.825,3.415c-0.673-0.021-1.305-0.206-1.859-0.513c0,0.017,0,0.034,0,0.052c0,1.988,1.414,3.647,3.292,4.023 c-0.344,0.094-0.707,0.144-1.081,0.144c-0.264,0-0.521-0.026-0.772-0.074c0.522,1.63,2.038,2.816,3.833,2.85 c-1.404,1.1-3.174,1.756-5.096,1.756c-0.331,0-0.658-0.019-0.979-0.057c1.816,1.164,3.973,1.843,6.29,1.843 c7.547,0,11.675-6.252,11.675-11.675c0-0.178-0.004-0.355-0.012-0.531C20.985,7.47,21.68,6.747,22.23,5.924z"></path>
															</svg>
															<span class="screen-reader-text">Twitter</span>
														</a>
													<?php } ?>
												</div>
											</div>
										</div>
									<?php } // foreach candidates ?>
								</div><!-- .candidates -->
							</div>
						<?php } // if/else blank candidates check ?>
					<?php } // foreach races ?>
				</div>
			<?php } // if/else blank races check
		} // foreach districts

		$response['ballot'] = ob_get_clean();
		wp_send_json_success($response);
//	}


}

add_action('wp_enqueue_scripts', 'np_electionkit_scripts');
function np_electionkit_scripts() {
	wp_enqueue_script('electionkit',  plugin_dir_url( __FILE__ ) . 'electionkit.js', array('jquery'), '1.0.0', true);

	$params = array(
		'ajaxurl' => admin_url('admin-ajax.php'),
		'ajax_nonce' => wp_create_nonce('electionkit-address'),
	);
	wp_localize_script( 'electionkit', 'ajax_object', $params );

	wp_enqueue_style('electionkit',  plugin_dir_url( __FILE__ ) . 'electionkit.css', array(), '1.0.0');
}

