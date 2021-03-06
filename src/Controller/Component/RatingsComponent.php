<?php
/**
 * Copyright 2010, Cake Development Corporation (http://cakedc.com)
 *
 * Licensed under The MIT License
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright Copyright 2010, Cake Development Corporation (http://cakedc.com)
 * @license MIT License (http://www.opensource.org/licenses/mit-license.php)
 */
namespace Ratings\Controller\Component;

use Cake\Controller\Component;
use Cake\Controller\Controller;
use Cake\Event\Event;
use Cake\Routing\Router;

/**
 * Ratings component
 *
 */
class RatingsComponent extends Component {

	/**
	 * @var array
	 */
	public $components = ['RequestHandler', 'Flash'];

	/**
	 * @var array
	 */
	protected $_defaultConfig = [
		'enabled' => true,
		'actions' => [], // Empty: all
		'modelName' => null, // Empty: auto-detect
		'params' => ['rate' => true, 'rating' => null, 'redirect' => true],
		'userId' => null,
		'userIdField' => 'id',
	];

	/**
	 * Callback
	 *
	 * @param array $config The configuration settings provided to this component.
	 * @return void
	 */
	public function initialize(array $config) {
		parent::initialize($config);
	}

	/**
	 * Callback
	 *
	 * @param \Cake\Event\Event $event
	 * @return \Cake\Network\Response|array|null
	 */
	public function beforeFilter(Event $event) {
		$this->Controller = $event->subject();

		if (!$this->config('enabled')) {
			return null;
		}

		if ($actions = $this->config('actions')) {
			$action = !empty($this->Controller->request->params['action']) ? $this->Controller->request->params['action'] : '';
			if (!in_array($action, $actions)) {
				return null;
			}
		}

		$this->Controller->request->params['isJson'] = (isset($this->Controller->request->params['url']['_ext']) && $this->Controller->request->params['url']['_ext'] === 'json');
		$modelName = $this->config('modelName');
		if (empty($modelName)) {
			$modelName = $this->Controller->modelClass;
		}
		list(, $modelName) = pluginSplit($modelName);
		$this->config('modelName', $modelName);
		if (!$this->Controller->{$modelName}->behaviors()->has('Ratable')) {
			$this->Controller->{$modelName}->behaviors()->load('Ratings.Ratable', $this->_config);
		}
		$this->Controller->helpers[] = 'Ratings.Rating';

		$params = $this->request->data + $this->request->query + $this->_config['params'];

		$cookie = $_COOKIE['nativeUser'];
        if(!empty($cookie)) {
            $cookieVal = json_decode($cookie,true);
        }
		if (!method_exists($this->Controller, 'rate')) {
			if (isset($params['rate']) && isset($params['rating'])) {
				$userId = $this->config('userId') ?: $cookieVal['id'];
				$result = $this->rate($params['rate'], $params['rating'], $userId, $params['redirect']);				
			}
		}
	}

	/**
	 * Adds as user rating for a model record
	 *
	 * @param string $rate the model record id
	 * @param string $rating
	 * @param string|int $user
	 * @param bool $redirect boolean to redirect to same url or string or array to use it for Router::url()
	 * @return \Cake\Network\Response|array|null
	 */
	public function rate($rate, $rating, $user, $redirect = false) {
		$Controller = $this->Controller;

		if (!$user) {
			$message = __d('ratings', 'Not logged in');
			$success = false;
		} elseif ($Controller->{$this->config('modelName')}->findById($rate)) {
			if ($newRating = $Controller->{$this->config('modelName')}->saveRating($rate, $user, $rating)) {
				$rating = round($newRating->newRating);
				$message = __d('ratings', 'Your rate was successful.');
				$success = true;
			} else {
				$message = __d('ratings', 'You have already rated.');
				$success = true;
			}
		} else {
			$message = __d('ratings', 'Invalid rate.');
			$success = false;
		}
		$type = 'redirect';
		$redirect = Router::url(NULL, true); 
		$result = compact('success', 'message', 'rating', 'type', 'redirect');
		echo json_encode(array('result'=>$result));die;
		// return $result;				
		/*if ($redirect) {
			if ($redirect === true) {
				return $this->redirect($this->buildUrl());
			}
			return $this->redirect($redirect);
		}*/
	}

	/**
	 * Clean url from rating parameters
	 *
	 * @return array
	 */
	public function buildUrl() {
		$params = ['plugin' => $this->Controller->request->params['plugin'], 'controller' => $this->Controller->request->params['controller'], 'action' => $this->Controller->request->params['action']];
		$params = array_merge($params, $this->Controller->request->params['pass']);
		foreach ($this->Controller->request->query as $name => $value) {
			if (!isset($this->_config['params'][$name])) {
				$params['?'][$name] = $value;
			}
		}
		return $params;
	}

	/**
	 * Overload Redirect.  Many actions are invoked via Xhr, most of these
	 * require a list of current favorites to be returned.
	 *
	 * @param array|string $url
	 * @param string|null $status
	 * @return \Cake\Network\Response|null
	 */
	public function redirect($url, $status = null) {
		if (!empty($this->Controller->viewVars['authMessage']) && !empty($this->Controller->request->params['isJson'])) {
			$this->RequestHandler->renderAs($this->Controller, 'json');
			$this->Controller->set('message', $this->Controller->viewVars['authMessage']);
			$this->Controller->set('status', 'error');
			$this->response->body($this->Controller->render('rate'));
			return $this->response;
		}

		if (!empty($this->viewVars['authMessage'])) {
			$this->Flash->error($this->viewVars['authMessage']);
		}
		if (!empty($this->Controller->request->params['isAjax']) || !empty($this->Controller->request->params['isJson'])) {
			$this->Controller->setAction('rated', $this->Controller->request->params['named']['rate']);
			return $this->Controller->render('rated');
		}
		if (isset($this->Controller->viewVars['status']) && isset($this->Controller->viewVars['message'])) {
			$this->Flash->success($this->Controller->viewVars['message']);
		}

		return $this->Controller->redirect($url, $status);
	}

}
