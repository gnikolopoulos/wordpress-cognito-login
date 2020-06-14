<?php
/*
  Plugin Name: Cognito Login
  Plugin URI: https://github.com/Trifoia/wordpress-cognito-login
  description: WordPress plugin for integrating with Cognito for user logins
  Version: 1.0.0
  Author: Trifoia
  Author URI: https://trifoia.com
*/

define( 'PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
include_once( PLUGIN_PATH . 'settings.php' );

// --- Include Utilities ---
include_once( PLUGIN_PATH . 'includes/utils/generate-strings.php' );

// --- Include Units ---
include_once( PLUGIN_PATH . 'includes/units/auth.php' );
include_once( PLUGIN_PATH . 'includes/units/programmatic-login.php' );
include_once( PLUGIN_PATH . 'includes/units/user.php' );

/**
 * General initialization function container
 */
class Cognito_Login{
  /**
   * The default shortcode returns an "a" tag, or a logout link, depending on if the user is
   * logged in
   */
  public static function shortcode_default() {
    $user = wp_get_current_user();

    if ( $user->{'ID'} !== 0 ) {
      return Cognito_Login_Generate_Strings::already_logged_in( $user->{'user_login'} );
    }

    return Cognito_Login_Generate_Strings::a_tag();
  }

  /**
   * Handler for the "query_vars" filter. Adds the query vars we need to the registry
   */
  public static function query_vars_handler( $qvars ) {
    $qvars[] = 'code';
    return $qvars;
  }

  /**
   * Handler for the "parse_query" action. This is the "main" function that listens for the
   * correct query variable that will trigger a login attempt
   */
  public static function parse_query_handler() {
    // Try to get a code from the url query and abort if we don't find one, or the user is already logged in
    $code = Cognito_Login_Auth::get_code();
    if ( $code === FALSE ) return;
    if ( is_user_logged_in() ) return;

    // Attempt to exchange the code for a token, abort if we weren't able to
    $token = Cognito_Login_Auth::get_token( $code );
    if ( $token === FALSE) return;

    // Parse the token
    $parsed_token = Cognito_Login_Auth::parse_jwt( $token['id_token'] );

    // Determine user existence
    if ( !in_array( get_option( 'username_attribute' ), $parsed_token ) ) return;
    $username = $parsed_token[get_option('username_attribute')];
    
    $user = get_user_by( 'login', $username );
    if ( $user === FALSE ) {
      // Create a new user only if the setting is turned on
      if ( get_option( 'create_new_user' ) !== 'true' ) return;

      // Create a new user and abort on failure
      $user = Cognito_Login_User::create_user( $parsed_token );
      if ( $user === FALSE ) return;
    }

    // Log the user in! Exit if the login fails
    if ( Cognito_Login_Programmatic_Login::login( $username ) === FALSE ) return;

    // Redirect the user to the "homepage", if it is set (this will hide all `print` statements)
    $homepage = get_option('homepage');
    if ( !empty( $homepage ) ) {
      Cognito_Login_Auth::redirect_to( $homepage );
    }
  }
}

// --- Add Shortcodes ---
add_shortcode( 'cognito_login', array('Cognito_Login', 'shortcode_default') );

// --- Add Filters ---
add_filter( 'query_vars', array('Cognito_Login', 'query_vars_handler') );

// --- Add Actions ---
add_action( 'parse_query', array('Cognito_Login', 'parse_query_handler') );
