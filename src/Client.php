<?php
/**
 * @section LICENSE
 * This file is part of the MediaWiki OAuth Client library
 *
 * The MediaWiki OAuth Client libraryis free software: you can
 * redistribute it and/or modify it under the terms of the GNU General Public
 * License as published by the Free Software Foundation, either version 3 of
 * the License, or (at your option) any later version.
 *
 * The MediaWiki OAuth Client library is distributed in the hope that it
 * will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty
 * of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU
 * General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with the MediaWiki OAuth Client library. If not, see
 * <http://www.gnu.org/licenses/>.
 *
 * @file
 * @copyright © 2015 Chris Steipp, Wikimedia Foundation and contributors.
 */

namespace MediaWiki\OAuthClient;

use MediaWiki\OAuthClient\SignatureMethod\HmacSha1;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * MediaWiki OAuth client.
 */
class Client implements LoggerAwareInterface {

	/**
	 * Number of seconds by which IAT (token issue time) can be larger than current time, to account
	 * for clock drift.
	 * @var int
	 */
	const IAT_TOLERANCE = 2;

	/**
	 * @var LoggerInterface $logger
	 */
	protected $logger;

	/**
	 * @var ClientConfig $config
	 */
	private $config;

	/**
	 * Any extra params in the call that need to be signed
	 * @var array $extraParams
	 */
	private $extraParams = array();

	/**
	 * url, defaults to oob
	 * @var string $callbackUrl
	 */
	private $callbackUrl = 'oob';

	/**
	 * Track the last random nonce generated by the OAuth lib, used to verify
	 * /identity response isn't a replay
	 * @var string $lastNonce
	 */
	private $lastNonce;

	/**
	 * @param ClientConfig $config
	 * @param LoggerInterface $logger
	 */
	function __construct(
		ClientConfig $config,
		LoggerInterface $logger = null
	) {
		$this->config = $config;
		$this->logger = $logger ?: new NullLogger();
	}

	/**
	 * @param LoggerInterface $logger
	 */
	public function setLogger( LoggerInterface $logger ) {
		$this->logger = $logger;
	}

	/**
	 * @param string $url
	 * @param string $key
	 * @param string $secret
	 * @return MediaWiki
	 */
	public static function newFromKeyAndSecret( $url, $key, $secret ) {
		$config = new ClientConfig( $url, true, true );
		$config->setConsumer( new Consumer( $key, $secret ) );
		return new static( $config );
	}

	/**
	 * @param string $key
	 * @param string $value
	 */
	public function setExtraParam( $key, $value ) {
		$this->extraParams[$key] = $value;
	}

	/**
	 * @param array $params
	 */
	public function setExtraParams( array $params ) {
		$this->extraParams = $params;
	}

	/**
	 * @param string $url
	 */
	public function setCallback( $url ) {
		$this->callbackUrl = $url;
	}

	/**
	 * First part of 3-legged OAuth, get the request Token.
	 * Redirect your authorizing users to the redirect url, and keep
	 * track of the request token since you need to pass it into complete()
	 *
	 * @return array (redirect, request/temp token)
	 */
	public function initiate() {
		$initUrl = $this->config->endpointURL .
			'/initiate&format=json&oauth_callback=' .
			urlencode( $this->callbackUrl );
		$data = $this->makeOAuthCall( null, $initUrl );
		$return = json_decode( $data );
		if ( $return->oauth_callback_confirmed !== 'true' ) {
			throw new Exception( "Callback wasn't confirmed" );
		}
		$requestToken = new Token( $return->key, $return->secret );
		$url = $this->config->redirURL ?:
			$this->config->endpointURL . "/authorize&";
		$url .= "oauth_token={$requestToken->key}&oauth_consumer_key={$this->config->consumer->key}";
		return array( $url, $requestToken );
	}

	/**
	 * The final leg of the OAuth handshake. Exchange the request Token from
	 * initiate() and the verification code that the user submitted back to you
	 * for an access token, which you'll use for all API calls.
	 *
	 * @param Token $requestToken Authorization code sent to the callback url
	 * @param string Temp/request token obtained from initiate, or null if this
	 *     object was used and the token is already set.
	 * @return Token The access token
	 */
	public function complete( Token $requestToken, $verifyCode ) {
		$tokenUrl = $this->config->endpointURL . '/token&format=json';
		$this->setExtraParam( 'oauth_verifier', $verifyCode );
		$data = $this->makeOAuthCall( $requestToken, $tokenUrl );
		$return = json_decode( $data );
		$accessToken = new Token( $return->key, $return->secret );
		// Cleanup after ourselves
		$this->setExtraParams = array();
		return $accessToken;
	}

	/**
	 * Optional step. This call the MediaWiki specific /identify method, which
	 * returns a signed statement of the authorizing user's identity. Use this
	 * if you are authenticating users in your application, and you need to
	 * know their username, groups, rights, etc in MediaWiki.
	 *
	 * @param Token $accessToken Access token from complete()
	 * @return object containing attributes of the user
	 */
	public function identify( Token $accessToken ) {
		$identifyUrl = $this->config->endpointURL . '/identify';
		$data = $this->makeOAuthCall( $accessToken, $identifyUrl );
		$identity = $this->decodeJWT( $data, $this->config->consumer->secret );
		if ( !$this->validateJWT(
			$identity,
			$this->config->consumer->key,
			$this->config->canonicalServerUrl,
			$this->lastNonce
		) ) {
			throw new Exception( "JWT didn't validate" );
		}
		return $identity;
	}

	/**
	 * Make a signed request to MediaWiki
	 *
	 * @param Token $token additional token to use in signature, besides
	 *     the consumer token. In most cases, this will be the access token you
	 *     got from complete(), but we set it to the request token when
	 *     finishing the handshake.
	 * @param string $url URL to call
	 * @param bool $isPost true if this should be a POST request
	 * @param array $postFields POST parameters, only if $isPost is also true
	 * @return string Body from the curl request
	 */
	public function makeOAuthCall(
		/*Token*/ $token, $url, $isPost = false, array $postFields = null
	) {
		$params = array();
		// Get any params from the url
		if ( strpos( $url, '?' ) ) {
			$parsed = parse_url( $url );
			parse_str( $parsed['query'], $params );
		}
		$params += $this->extraParams;
		if ( $isPost && $postFields ) {
			$params += $postFields;
		}
		$method = $isPost ? 'POST' : 'GET';
		$req = Request::fromConsumerAndToken(
			$this->config->consumer,
			$token,
			$method,
			$url,
			$params
		);
		$req->signRequest(
			new HmacSha1(),
			$this->config->consumer,
			$token
		);
		$this->lastNonce = $req->getParameter( 'oauth_nonce' );
		return $this->makeCurlCall(
			$url,
			$req->toHeader(),
			$isPost,
			$postFields,
			$this->config
		);
	}

	/**
	 * @param string $url
	 * @param array $headers
	 * @param bool $isPost
	 * @param array $postFields
	 * @return string
	 */
	private function makeCurlCall(
		$url, $headers, $isPost, array $postFields = null
	) {
		$ch = curl_init();
		curl_setopt( $ch, CURLOPT_URL, (string) $url );
		curl_setopt( $ch, CURLOPT_HEADER, 0 );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );
		curl_setopt( $ch, CURLOPT_HTTPHEADER, array( $headers ) );
		if ( $isPost ) {
			curl_setopt( $ch, CURLOPT_POST, true );
			curl_setopt( $ch, CURLOPT_POSTFIELDS, http_build_query( $postFields ) );
		}
		if ( $this->config->useSSL ) {
			curl_setopt( $ch, CURLOPT_PORT, 443 );
		}
		if ( $this->config->verifySSL ) {
			curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, true );
			curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, 2 );
		} else {
			curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false );
			curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, 0 );
		}
		$data = curl_exec( $ch );
		if ( !$data ) {
			if ( curl_errno( $ch ) ) {
				throw new Exception( 'Curl error: ' . curl_error( $ch ) );
			} else {
				throw new Exception( 'Empty HTTP response! Status: '
					. curl_getinfo( $ch, CURLINFO_HTTP_CODE ) );
			}
		}
		return $data;
	}

	/**
	 * @param string $JWT Json web token
	 * @param string $secret
	 * @return object
	 */
	private function decodeJWT( $JWT, $secret ) {
		list( $headb64, $bodyb64, $sigb64 ) = explode( '.', $JWT );
		$header = json_decode( $this->urlsafeB64Decode( $headb64 ) );
		$payload = json_decode( $this->urlsafeB64Decode( $bodyb64 ) );
		$sig = $this->urlsafeB64Decode( $sigb64 );
		// MediaWiki will only use sha256 hmac (HS256) for now. This check
		// makes sure an attacker doesn't return a JWT with 'none' signature
		// type.
		$expectSig = hash_hmac(
			'sha256', "{$headb64}.{$bodyb64}", $secret, true
		);
		if ( $header->alg !== 'HS256' || !$this->compareHash( $sig, $expectSig ) ) {
			throw new Exception( "Invalid JWT signature from /identify." );
		}
		return $payload;
	}

	/**
	 * @param object $identity
	 * @param string $consumerKey
	 * @param string $expectedConnonicalServer
	 * @param string $nonce
	 */
	protected function validateJWT(
		$identity, $consumerKey, $expectedConnonicalServer, $nonce
	) {
		// Verify the issuer is who we expect (server sends $wgCanonicalServer)
		if ( $identity->iss !== $expectedConnonicalServer ) {
			$this->logger->info(
				"Invalid issuer '{$identity->iss}': expected '{$expectedConnonicalServer}'" );
			return false;
		}
		// Verify we are the intended audience
		if ( $identity->aud !== $consumerKey ) {
			$this->logger->info( "Invalid audience '{$identity->aud}': expected '{$consumerKey}'" );
			return false;
		}
		// Verify we are within the time limits of the token. Issued at (iat)
		// should be in the past, Expiration (exp) should be in the future.
		$now = time();
		if ( $identity->iat > $now + static::IAT_TOLERANCE || $identity->exp < $now ) {
			$this->logger->info(
				"Invalid times issued='{$identity->iat}', " .
				"expires='{$identity->exp}', now='{$now}'"
			);
			return false;
		}
		// Verify we haven't seen this nonce before, which would indicate a replay attack
		if ( $identity->nonce !== $nonce ) {
			$this->logger->info( "Invalid nonce '{$identity->nonce}': expected '{$nonce}'" );
			return false;
		}
		return true;
	}

	/**
	 * @param string $input
	 * @return string
	 */
	private function urlsafeB64Decode( $input ) {
		$remainder = strlen( $input ) % 4;
		if ( $remainder ) {
			$padlen = 4 - $remainder;
			$input .= str_repeat( '=', $padlen );
		}
		return base64_decode( strtr( $input, '-_', '+/' ) );
	}

	/**
	 * Constant time comparison
	 * @param string $hash1
	 * @param string $hash2
	 * @return bool
	 */
	private function compareHash( $hash1, $hash2 ) {
		$result = strlen( $hash1 ) ^ strlen( $hash2 );
		$len = min( strlen( $hash1 ), strlen( $hash2 ) ) - 1;
		for ( $i = 0; $i < $len; $i++ ) {
			$result |= ord( $hash1{$i} ) ^ ord( $hash2{$i} );
		}
		return $result == 0;
	}
}
