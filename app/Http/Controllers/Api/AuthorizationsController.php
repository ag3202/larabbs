<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Api\AuthorizationRequest;
use Zend\Diactoros\Response as Psr7Response;
use App\Models\User;
use Auth;
use Illuminate\Http\Request;
use App\Http\Requests\Api\SocialAuthorizationRequest;
use League\OAuth2\Server\AuthorizationServer;
use Psr\Http\Message\ServerRequestInterface;
use App\Traits\PassportToken;

class AuthorizationsController extends Controller
{
    use PassportToken;
 	public function socialStore($type,  SocialAuthorizationRequest $request)
 	{

 		if(!in_array($type,['weixin'])) {
 			return $this->response->errorBadRequest();
 		}

 		$driver = \Socialite::driver($type);

 		try {
 			if ($code = $request->code){
 				$response = $driver->getAccessTokenResponse($code);
 				$token = array_get($response,'access_token');
 			}else{
 				$token = $request->access_token;

 				if($type = 'weixin'){
 					$driver->setOpenId($request->openid);
 				}
 			}

 			$oauthUser = $driver->userFromToken($token);
 		} catch (\Exception $e) {
 			return $this->response->errorUnauthorized('参数错误，未获取用户信息');
 		}

 		switch ($type) {
 			case 'weixin':
 				$unionid = $oauthUser->offsetExists('unionid') ?  $oauthUser->offsetGet('unionid') : null;

 				if($unionid) {
 					$user = User::where('weixin_unionid',$unionid)->first();
 				} else {
 					$user = User::where('weixin_openid',$oauthUser->getId())->first();
 				}

 				//没有用户，默认创建一个用户
 				if (!$user){
 					$user = User::create([
 						'name' => $oauthUser->getNickname(),
 						'avatar' => $oauthUser->getAvatar(),
 						'weixin_openid' => $oauthUser->getId(),
 						'weixin_unionid' => $unionid,
 					]);
 				}
 				break;
 			default:
 				# code...
 				break;
 		}

// 		$token = Auth::guard('api')->fromUser($user);
 		$token = $this->getBearerTokenByUser($user,'1',false);
 		return $this->respondWithToken($token)->serStatusCode(201);
 	}

    public function store(AuthorizationRequest $originRequest, AuthorizationServer $server, ServerRequestInterface $serverRequest)
    {
        try {
            return $server->respondToAccessTokenRequest($serverRequest, new Psr7Response)->withStatus(201);
        } catch(OAuthServerException $e) {
            return $this->response->errorUnauthorized($e->getMessage());
        }

 	}

 	protected function respondWithToken($token)
    {
        return $this->response->array([
                'access_token' => $token,
                'token_type' => 'Bearer',
                'expires_in' => \Auth::guard('api')->factory()->getTTL() * 60
        ]);
    }

    public function update(AuthorizationServer $server, ServerRequestInterface $serverRequest)
    {
        try {
            return $server->respondToAccessTokenRequest($serverRequest, new Psr7Response);
        } catch(OAuthServerException $e) {
            return $this->response->errorUnauthorized($e->getMessage());
        }
    }

    public function destroy()
    {
        $this->user()->token()->revoke();
        return $this->response->noContent();
    }
}
