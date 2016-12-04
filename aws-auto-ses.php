<?php
/**
 * Plugin Name: AWS Automatic SES
 * Version: 0.0.1
 * Description: Automatically configures WordPress to use SES when on an EC2 instance with a SES capable role.
 * Author: Redwerks Systems Inc.
 * Author URI: http://redwerks.org/
 * License: GPLv2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 */

use Aws\Ses\Exception\SesException;
use Aws\Ses\SesClient;

if ( is_readable(__DIR__ . '/vendor/autoload.php') ) {
	require_once(__DIR__ . '/vendor/autoload.php');
} else {
	if ( is_admin() ) {
		add_action('admin_notices', function() {
			printf('<div class="error"><p>%s</p></div>',
				sprintf(
					__("The AWS SDK is not available, please run <kbd>php composer.php install</kbd> in <code>%s</code>.", 'aws-auto-ses'),
						__DIR__));
		});
	}
}

function awsautoses_get_instance_document() {
	static $instanceDocument = null;

	if ( !isset($instanceDocument) ) {
		$documentPath = sys_get_temp_dir() . '/ec2-instance-identity-document.json';
		if ( file_exists($documentPath) ) {
			$instanceDocumentBody = file_get_contents($documentPath);
		} else {
			$instanceDocumentBody = wp_remote_retrieve_body(wp_remote_get('http://169.254.169.254/latest/dynamic/instance-identity/document'));
			file_put_contents($documentPath, $instanceDocumentBody);
		}

		$instanceDocument = json_decode($instanceDocumentBody);
	}

	return $instanceDocument;
}

function awsautoses_sesclient() {
	static $client = null;

	if ( !isset($client) ) {
		$instanceDocument = awsautoses_get_instance_document();

		if ( $instanceDocument && isset($instanceDocument->region) ) {
			$client = SesClient::factory(array(
				// 'credentials.cache' => new DoctrineCacheAdapter(new FilesystemCache(sys_get_temp_dir() . '/cache')),
				'region' => $instanceDocument->region,
				'version' => '2010-12-01'
			));
		}
	}

	return $client;
}

function awsautoses_twig() {
	$cache = WP_CONTENT_DIR . '/cache/aws-auto-ses/tplcache';
	if ( !is_dir($cache) ) {
		if ( !mkdir($cache, 0755, true) ) {
			$cache = false;
		}
	} else if ( is_writable($cache) ) {
		$cache = false;
	}

	$loader = new Twig_Loader_Filesystem(__DIR__ . '/tpl');
	$twig = new Twig_Environment($loader, array(
		'cache' => $cache
	));
	$twig->addExtension(new Twig_Extension_Escaper('html'));
	foreach ( array( '__' ) as $fn ) {
		$twig->addFunction(new Twig_SimpleFunction($fn, function() use($fn) {
			$args = func_get_args();
			return call_user_func_array($fn, $args);
		}));
	}
	foreach ( array( 'settings_fields', 'do_settings_sections', 'submit_button' ) as $fn ) {
		$twig->addFunction(new Twig_SimpleFunction($fn, function() use($fn) {
			$args = func_get_args();
			ob_start();
			call_user_func_array($fn, $args);
			return ob_get_clean();
		}));
	}

	return $twig;
}

function awsautoses_test() {
	$instanceDocument = awsautoses_get_instance_document();
	// var_dump($instanceDocument->region);
}

function awsautoses_options() {
	$options = get_option('aws_auto_ses_options', array());
	$options += array(
		'from' => null,
		'use_verified' => false
	);
	return $options;
}

function awsautoses_option($option, $default=null) {
	$options = awsautoses_options();
	return isset($options[$option]) ? $options[$option] : $default;
}

if ( is_admin() ) {
	add_action('init', function() {
		
	});

	add_action('admin_init', function() {
		register_setting('aws-auto-ses-settings', 'aws_auto_ses_options',
			function($input) {
				$output = get_option('aws_auto_ses_options', array());

				if ( !$input['from'] ) {
					$output['from'] = null;
				} else if ( is_email($input['from']) ) {
					$output['from'] = sanitize_email($input['from']);
				} else {
					add_settings_error('from', 'from',
						sprintf(__("\"%s\" doesn't look like an email address.", 'aws-auto-ses'),
							esc_html($input['from'])));
				}

				if ( $input['use_verified'] ) {
					try {
						awsautoses_sesclient()->getIdentityVerificationAttributes(array(
							'Identities' => array($input['from'] ? $input['from'] : get_bloginfo('admin_email'))
						));

						$output['use_verified'] = true;
					} catch ( SesException $e ) {
						add_settings_error('use_verified', 'use-verified',
							__("Please grant <code>ses:GetIdentityVerificationAttributes</code> to this server's role.", 'aws-auto-ses'));
						$output['use_verified'] = false;
					}
				} else {
					$output['use_verified'] = false;
				}

				return $output;
			});

		add_settings_section(
			'aws-auto-ses-settings',
			__('Settings', 'aws-auto-ses'),
			function() {
				
			},
			'aws-auto-ses-options');

		add_settings_field(
			'from',
			__('Sender Address', 'aws-auto-ses'),
			function() {
				echo '<input class="regular-text ltr" type="email" name="aws_auto_ses_options[from]" aria-describedby="awsautoses-from-description" value="' . esc_attr(awsautoses_option('from')) . '" placeholder="' . esc_attr(get_bloginfo('admin_email')) . '" />';
				echo '<p class="description" id="awsautoses-from-description">' . esc_html__("This address is used as the default from address. Leave blank to use the site admin email.", 'aws-auto-ses') . '</p>';
			},
			'aws-auto-ses-options',
			'aws-auto-ses-settings');

		add_settings_field(
			'use_verified',
			__('Verified senders', 'aws-auto-ses'),
			function() {
				try {
					$client = awsautoses_sesclient();
					$from = awsautoses_option('from', get_bloginfo('admin_email'));
					$identityVerifications = $client->getIdentityVerificationAttributes(array(
						'Identities' => array($from)
					));
					$authorized = true;
				} catch ( SesException $e ) {
					$authorized = false;
				}

				echo '<label>';
				echo '<input type="checkbox" name="aws_auto_ses_options[use_verified]" aria-describedby="awsautoses-use_verified-description" value="1"' . disabled(false, $authorized, false) . checked(1, awsautoses_option('use_verified'), false) . ' />';
				esc_html_e('Use from address when verified', 'aws-auto-ses');
				echo '</label>';
				echo '<p class="description" id="awsautoses-use_verified-description">' . esc_html__("When enabled the from address in an email will not be replaced with the default sender address if the from address has been verfiejd.", 'aws-auto-ses') . '</p>';
				if ( !$authorized ) {
					echo '<p class="description">' . __("Grant <code>ses:GetIdentityVerificationAttributes</code> to this server's role to use this option.", 'aws-auto-ses') . '</p>';
				}
			},
			'aws-auto-ses-options',
			'aws-auto-ses-settings');

	});

	add_action('admin_menu', function() {
		add_options_page(
			__('SES Options', 'aws-auto-ses'),
			__('SES Options', 'aws-auto-ses'),
			'manage_options',
			'aws-auto-ses-options',
			function() {
				global $phpmailer;
				$instanceDocument = awsautoses_get_instance_document();
				awsautoses_test();

				$client = awsautoses_sesclient();
				$twig = awsautoses_twig();
 				if ( $instanceDocument && isset($instanceDocument->region) && $client ) {
					$options = array();

					$options['region'] = $instanceDocument->region;

					$from = awsautoses_option('from', get_bloginfo('admin_email'));
					$from_domain = preg_replace('/^.+?@/', '', $from);
					try {
						$identityVerifications = $client->getIdentityVerificationAttributes(array(
							'Identities' => array(
								$from,
								$from_domain
							)
						));
						$va = $identityVerifications['VerificationAttributes'];
						if ( isset($va[$from]) && $va[$from]['VerificationStatus'] === 'Success' ) {
							$options['identityVerification'] = array(
								'email' => $from,
								'verified' => true,
								'scope' => 'email'
							);
						} elseif ( isset($va[$from_domain]) && $va[$from_domain]['VerificationStatus'] === 'Success' ) {
							$options['identityVerification'] = array(
								'email' => $from,
								'verified' => true,
								'scope' => 'domain'
							);
						} else {
							$options['identityVerification'] = array(
								'email' => $from,
								'verified' => false
							);
						}
					} catch ( SesException $e ) {
						$options['identityVerification'] = array(
							'email' => $from,
							'verified' => false
						);
						$options['identityVerificationError'] = array(
							'code' => $e->getAwsErrorCode(),
							'message' => $e->getMessage()
						);
					}

					$options['nonce'] = wp_create_nonce(__FILE__);
					$options['enabled'] = get_option('aws_auto_ses_enabled', false);
					$options['canEnable'] = $options['identityVerification']['verified'] || (isset($options['identityVerificationError']) && $options['identityVerificationError']['code'] === 'AccessDenied');

					try {
						$listIdentities = $client->listIdentities(array(
							'MaxItems' => 15
						));

						$options['identities'] = array_map(function($identity) {
							return array(
								'raw' => $identity,
								'text' => strpos($identity, '@') === false
									? "*@{$identity}"
									: $identity,
								'type' => strpos($identity, '@') === false
									? __('Domain', 'aws-auto-ses')
									: __('Email', 'aws-auto-ses'),
								'verified' => null,
							);
						}, $listIdentities['Identities']);

						try {
							$identityVerifications = $client->getIdentityVerificationAttributes(array(
								'Identities' => $listIdentities['Identities']
							));

							foreach ( $options['identities'] as $i => $identity ) {
								if ( isset($identityVerifications['VerificationAttributes'][$identity['raw']]) ) {
									$options['identities'][$i]['verified'] = $identityVerifications['VerificationAttributes'][$identity['raw']]['VerificationStatus'];
								}
							}
						} catch ( SesException $e ) {
							// Ignore AccessDenied errors and simply
							if ( $e->getAwsErrorCode() !== 'AccessDenied' ) {
								throw $e;
							}
						}

						$options['moreIdentities'] = (bool)$listIdentities['NextToken'];
						$options['dashboardLink'] = sprintf('https://%1$s.console.aws.amazon.com/ses/home?region=%1$s', $instanceDocument->region);
					} catch ( SesException $e ) {
						$options['identityRequestError'] = array(
							'code' => $e->getAwsErrorCode(),
							'message' => $e->getMessage()
						);
					}

					$current_user = wp_get_current_user();
					$options['useremail'] = $current_user->user_email;

					if ( $_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['action'] === 'sendtestemail' && check_admin_referer(__FILE__) ) {
						if ( awsautoses_mail($_REQUEST['to'], __('Test email', 'aws-auto-ses'), __('This email was sent using SES.', 'aws-auto-ses')) ) {
							$options['testemail'] = array(
								'result' => true
							);
						} else {
							$options['testemail'] = array(
								'result' => false,
								'error' => $phpmailer->ErrorInfo
							);
						}
					}

					if ( $_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['action'] === 'disable' && check_admin_referer(__FILE__) ) {
						update_option('aws_auto_ses_enabled', false);
						$options['enabled'] = false;
					}

					if ( $_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['action'] === 'enable' && check_admin_referer(__FILE__) ) {
						$subject = __('SES Enabled', 'aws-auto-ses');
						$message = sprintf(__('SES has been enabled on %s.', 'aws-auto-ses'),
							get_bloginfo('url'));
						if ( awsautoses_mail($options['useremail'], $subject, $message) ) {
							update_option('aws_auto_ses_enabled', true);
							$options['enable'] = array(
								'result' => true
							);
							$options['enabled'] = true;
						} else {
							$options['enable'] = array(
								'result' => false,
								'error' => $phpmailer->ErrorInfo
							);
						}
					}

					echo $twig->render('options.html', $options);
				} else {
					echo $twig->render('options.html', array(
					));
				}
			}
		);
	});

	add_filter('plugin_action_links', function($links, $file) {
		static $self = null;
		if ( !isset($self) ) {
			$self = plugin_basename(__FILE__);
		}

		if ( $file === $self ) {
			array_unshift($links,
				sprintf('<a href="%s">%s</a>',
					esc_url(admin_url('options-general.php?page=aws-auto-ses-options')),
					esc_html__('Settings', 'aws-auto-ses')));
		}

		return $links;
	}, 10, 2);

	register_activation_hook(__FILE__, function() {
		
	});
}

function awsautoses_enabled() {
	$enabled = get_option('aws_auto_ses_enabled', false);
	$enabled = apply_filters('awsautoses_enabled', $enabled);

	return $enabled;
}

function awsautoses_mail() {
	// Force awsautoses to be enabled during this call with a filter then reset it
	$args = func_get_args();
	add_filter('awsautoses_enabled', '__return_true');
	$result = call_user_func_array('wp_mail', $args);
	remove_filter('awsautoses_enabled', '__return_true');
	return $result;
}

add_action('phpmailer_init', function(&$phpmailer) {
	$enabled = awsautoses_enabled();

	if ( !$enabled ) {
		return;
	}

	if ( !($phpmailer instanceof SESPHPMailer) ) {
		$phpmailer = SESPHPMailer::replace($phpmailer);
	}

	$phpmailer->isSES();
});

function awsautoses_verified($email) {
	$key = "awsautoses_verified_$email";
	$verified = get_transient($key);
	if ( !is_string($verified) ) {
		$domain = preg_replace('/^.+?@/', '', $email);
		$identityVerifications = awsautoses_sesclient()->getIdentityVerificationAttributes(array(
			'Identities' => array(
				$email,
				$domain
			)
		));
		$va = $identityVerifications['VerificationAttributes'];
		if ( isset($va[$email]) && $va[$email]['VerificationStatus'] === 'Success' ) {
			$verified = 'y';
		} elseif ( isset($va[$domain]) && $va[$domain]['VerificationStatus'] === 'Success' ) {
			$verified = 'y';
		} else {
			$verified = 'n';
		}

		set_transient($key, $verified, WEEK_IN_SECONDS);
	}

	return $verified === 'y';
}

add_filter('wp_mail_from', function($from) {
	// If use_verified is enabled and $from is verified use it
	if ( awsautoses_option('use_verified', false) && awsautoses_verified($from) ) {
		return $from;
	}

	return awsautoses_option('from', get_bloginfo('admin_email'));
}, 9999);
