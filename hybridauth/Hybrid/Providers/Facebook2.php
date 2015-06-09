<?php
/*!
* HybridAuth
* http://hybridauth.sourceforge.net | http://github.com/hybridauth/hybridauth
* (c) 2009-2012, HybridAuth authors, Kevin Gregull | http://hybridauth.sourceforge.net/licenses.html 
*/

/**
 * Hybrid_Providers_Facebook provider adapter based on OAuth2 protocol
 * 
 * Hybrid_Providers_Facebook use the Facebook PHP SDK created by Facebook
 * 
 * http://hybridauth.sourceforge.net/userguide/IDProvider_info_Facebook.html
 */
use Facebook\FacebookSDKException;
use Facebook\FacebookSession;
use Facebook\GraphObject;
use Facebook\GraphUser;
use Facebook\FacebookRequest;
use Facebook\FacebookRequestException;
use Facebook\FacebookRedirectLoginHelper;
 
class Hybrid_Providers_Facebook extends Hybrid_Provider_Model
{
	// default permissions, and a lot of them. You can change them from the configuration by setting the scope to what you want/need
	public $scope=array("email","public_profile");

	/**
	* IDp wrappers initializer 
	*/
	function initialize() 
	{
		if ( ! $this->config["keys"]["id"] || ! $this->config["keys"]["secret"] ){
			throw new Exception( "Your application id and secret are required in order to connect to {$this->providerId}.", 4 );
		}

		if ( ! class_exists('FacebookSDKException', false) ) {
			// Register FB Autoloader
			spl_autoload_register(function($class)
			{
				$prefix='Facebook\\';

				// does the class use the namespace prefix?
				$len=strlen($prefix);
				if (strncmp($prefix, $class, $len) !== 0) {
				return;
				}

				$relative_class=substr($class,$len);
				$file=Hybrid_Auth::$config["path_libraries"]."Facebook/".str_replace('\\','/',$relative_class).'.php';
				if (file_exists($file))
				{
				require $file;
				}
			});
		}
		
		// Replace Scope if Parameter Present
		if (isset($this->config["scope"]))
		{
			$this->scope=$this->config["scope"];
		}

		FacebookSession::setDefaultApplication($this->config["keys"]["id"],$this->config["keys"]["secret"]);

		// Login via existing Access Token
		if ($this->token("access_token"))
		{
			$this->api=new FacebookSession($this->token("access_token"));
		}
	}

	/**
	* begin login step
	* 
	* simply call Facebook::require_login(). 
	*/
	function loginBegin()
	{
		try
		{
			$helper=new FacebookRedirectLoginHelper($this->endpoint);
			$url=$helper->getLoginUrl($this->scope);
		}
		catch (Exception $ex)
		{
			throw new Exception( "Unable to get LoginURL from Facebook API.", 5 );
		}

		// redirect to facebook
		Hybrid_Auth::redirect($url);
	}

	/**
	* finish login step 
	*/
	function loginFinish()
	{ 
		$helper=new FacebookRedirectLoginHelper($this->endpoint);
		try
		{
			$this->api=$helper->getSessionFromRedirect();
		}
		catch(Exception $ex)
		{
			throw new Exception("The User denied the Login request",5);
		}
		
		if ($this->api)
		{
			$this->setUserConnected();
			$this->token("access_token",$this->api->getToken());
		}
		else
		{
			throw new Exception( "Authentication failed! {$this->providerId} returned an invalid user id.", 5 );
		}
	}

	/**
	* logout
	*/
	function logout()
	{ 
		parent::logout();
	}

	/**
	* load the user profile from the IDp api client
	*/
	function getUserProfile()
	{
		// request user profile from fb api
		try{
			$request=new FacebookRequest($this->api,'GET','/me');
			$response=$request->execute();
			$data=$response->getGraphObject(GraphUser::className());
			$data=$data->asArray();
		}
		catch( FacebookApiException $e ){
			throw new Exception( "User profile request failed! {$this->providerId} returned an error: $e", 6 );
		} 

		// if the provider identifier is not received, we assume the auth has failed
		if ( ! isset( $data["id"] ) ){ 
			throw new Exception( "User profile request failed! {$this->providerId} api returned an invalid response.", 6 );
		}

		# store the user profile.
		$this->user->profile->identifier    = (array_key_exists('id',$data))?$data['id']:"";
		$this->user->profile->username      = (array_key_exists('username',$data))?$data['username']:"";
		$this->user->profile->displayName   = (array_key_exists('name',$data))?$data['name']:"";
		$this->user->profile->firstName     = (array_key_exists('first_name',$data))?$data['first_name']:"";
		$this->user->profile->lastName      = (array_key_exists('last_name',$data))?$data['last_name']:"";
		$this->user->profile->photoURL      = "https://graph.facebook.com/" . $this->user->profile->identifier . "/picture?width=150&height=150";
		$this->user->profile->coverInfoURL  = "https://graph.facebook.com/" . $this->user->profile->identifier . "?fields=cover&access_token=".$this->api->getAccessToken();
		$this->user->profile->profileURL    = (array_key_exists('link',$data))?$data['link']:""; 
		$this->user->profile->webSiteURL    = (array_key_exists('website',$data))?$data['website']:""; 
		$this->user->profile->gender        = (array_key_exists('gender',$data))?$data['gender']:"";
        	$this->user->profile->language      = (array_key_exists('locale',$data))?$data['locale']:"";
		$this->user->profile->description   = (array_key_exists('about',$data))?$data['about']:"";
		$this->user->profile->email         = (array_key_exists('email',$data))?$data['email']:"";
		$this->user->profile->emailVerified = (array_key_exists('email',$data))?$data['email']:"";
		$this->user->profile->region        = (array_key_exists("hometown",$data)&&array_key_exists("name",$data['hometown']))?$data['hometown']["name"]:"";
		
		if(!empty($this->user->profile->region )){
			$regionArr = explode(',',$this->user->profile->region );
			if(count($regionArr) > 1){
				$this->user->profile->city = trim($regionArr[0]);
				$this->user->profile->country = trim($regionArr[1]);
			}
		}
		
		if( array_key_exists('birthday',$data) ) {
			list($birthday_month, $birthday_day, $birthday_year) = explode( "/", $data['birthday'] );
			$this->user->profile->birthDay   = (int) $birthday_day;
			$this->user->profile->birthMonth = (int) $birthday_month;
			$this->user->profile->birthYear  = (int) $birthday_year;
		}

		return $this->user->profile;
 	}

	/**
	* Attempt to retrieve the url to the cover image given the coverInfoURL
	*
	* @param  string $coverInfoURL   coverInfoURL variable
	* @retval string                 url to the cover image OR blank string
	*/
	function getCoverURL($coverInfoURL)
	{
		return false;
	}
	
	/**
	* load the user contacts
	*/
	function getUserContacts()
	{
		return false;
 	}

    /**
    * update user status
	*
	* @param  string $pageid   (optional) User page id
    */
    function setUserStatus( $status, $pageid = null )
    {
        return false;
    }


	/**
	* get user status
	*/
    function getUserStatus( $postid )
    {
		return false;
    }


	/**
	* get user pages
	*/
    function getUserPages( $writableonly = false )
    {
        return false;
    }

	/**
	* load the user latest activity  
	*    - timeline : all the stream
	*    - me       : the user activity only  
	*/
	function getUserActivity( $stream )
	{
		return false;
 	}
}
