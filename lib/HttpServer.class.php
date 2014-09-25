<?php
namespace lib;

use \Exception;
use Ratchet\Http\HttpServerInterface;
use Ratchet\ConnectionInterface;
use Guzzle\Http\Message\RequestInterface;
use Guzzle\Http\Message\Response;
use Guzzle\Http\QueryString;

use Ratchet\Session\Storage\VirtualSessionStorage;
use Ratchet\Session\Serialize\HandlerInterface;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\Handler\NullSessionHandler;

/**
 * A builtin HTTP server.
 * @since 1.0beta5
 * @author emersion
 */
class HttpServer implements HttpServerInterface {
	protected $sessions = array();

	public function __construct(\SessionHandlerInterface $handler, HandlerInterface $serializer = null) {
		$this->_handler = $handler;

		ini_set('session.auto_start', 0);
		ini_set('session.cache_limiter', '');
		ini_set('session.use_cookies', 0);

		if (null === $serializer) {
			$serialClass = "Ratchet\\Session\\Serialize\\{$this->toClassCase(ini_get('session.serialize_handler'))}Handler"; // awesome/terrible hack, eh?
			if (!class_exists($serialClass)) {
				throw new \RuntimeException('Unable to parse session serialize handler');
			}

			$serializer = new $serialClass;
		}

		$this->_serializer = $serializer;
	}

	public function onOpen(ConnectionInterface $from, RequestInterface $request = null) {
		// Session management
		$saveHandler = $this->_handler;
		if (empty($id = $request->getCookie(ini_get('session.name')))) {
			$id = sha1(uniqid() . mt_rand());
		}

		// Crappy workaround for sessions - don't know why they are not saved
		// @see https://github.com/ratchetphp/Ratchet/blob/master/src/Ratchet/Session/SessionProvider.php
		if (isset($this->sessions[$id])) {
			$from->Session = $this->sessions[$id];
		} else {
			$from->Session = new Session(new VirtualSessionStorage($saveHandler, $id, $this->_serializer));
			$this->sessions[$id] = $from->Session;
		}

		if (ini_get('session.auto_start')) {
			$from->Session->start();
		}


		$requestPath = $request->getPath();

		$body = (string)$request->getBody();
		if (!empty($body)) {
			$query = QueryString::fromString($body);
			$fields = $query->getAll();
			$request->addPostFields($fields);
		}

		$_SERVER['HTTP_ACCEPT_LANGUAGE'] = (string) $request->getHeaders()->get('accept-language');

		$routes = array(
			'/' => 'executeUiBooter',
			'/sbin/apicall.php' => 'executeApiCall',
			'/sbin/apicallgroup.php' => 'executeApiCallGroup',
			'/sbin/rawdatacall.php' => 'executeRawDataCall',
			'#^/([a-zA-Z0-9-_.]+)\.html$#' => 'executeUiBooter',
			'#^/(bin|boot|etc|home|tmp|usr|var)/(.*)$#' => 'executeReadFile',
			'/webos.webapp' => 'executeReadManifest'
		);

		foreach ($routes as $path => $method) {
			$matched = false;
			if (substr($path, 0, 1) == '#') { // Regex
				if (preg_match($path, $requestPath, $matches)) {
					$result = $this->$method($from, $request, $matches);
					$matched = true;
				}
			} else {
				if ($path == $requestPath) {
					$result = $this->$method($from, $request);
					$matched = true;
				}
			}

			if ($matched) {
				if (empty($result)) {
					$result = '';
				}
				if ($result instanceof ResponseContent) {
					$result = $result->generate();
				}
				if ($result instanceof HttpServerResponse) {
					if ($result->headersSent()) { // Implicit mode, content already sent
						$from->close();
					} else {
						$result->send();
					}
					return;
				}

				$response = null;
				if (is_string($result)) {
					$response = new Response(200, array(), (string)$result);
				} else {
					$response = $result;
				}
				break;
			}
		}

		$from->send((string)$response);
		$from->close();
	}

	public function onMessage(ConnectionInterface $from, $msg) {}
	public function onError(ConnectionInterface $from, Exception $e) {}
	public function onClose(ConnectionInterface $from) {}

	protected function getRequest($conn, $req) {
		return new HTTPServerRequest($conn, $req);
	}

	protected function getResponse($conn, $req) {
		$res = new HTTPServerResponse($conn);

		$res->addHeader('Connection: close');

		// Send cookie for sessions
		if ($conn->Session->isStarted() && $conn->Session->getId() != $req->getCookie(ini_get('session.name'))) {
			$cookiesParams = session_get_cookie_params();

			$header = ini_get('session.name').'='.$conn->Session->getId().';'
				.'Path='.$cookiesParams['path'];
			if (!empty($cookiesParams['lifetime'])) {
				$header .= ';Max-Age='.$cookiesParams['lifetime'];
			}
			if (!empty($cookiesParams['domain'])) {
				$header .= ';Domain='.$cookiesParams['domain'];
			}
			if ($cookiesParams['secure']) {
				$header .= ';Secure';
			}
			if ($cookiesParams['httponly']) {
				$header .= ';HttpOnly';
			}
			$res->addHeader('Set-Cookie: ' . $header);
		}

		return $res;
	}

	protected function executeUiBooter($conn, $req, $matches = null) {
		if (!empty($matches)) {
			$req->getQuery()->set('ui', $matches[1]);
		}

		$request = $this->getRequest($conn, $req);
		$response = $this->getResponse($conn, $req);

		$bootstrap = new UserInterfaceBooter();
		$bootstrap->emulate(null, $request, $response);
		$bootstrap->run();

		return $bootstrap->httpResponse();
	}

	protected function executeApiCall($conn, $req) {
		$request = $this->getRequest($conn, $req);
		$response = $this->getResponse($conn, $req);

		$apiCall = new Api;
		$apiCall->emulate($req->getPostFields()->getAll(), $request, $response);
		$apiCall->run();

		return $apiCall->httpResponse();
	}

	protected function executeApiCallGroup($conn, $req) {
		/*$request = new HTTPServerRequest($conn, $req);
		$response = new HTTPServerResponse($conn);

		$apiGroupCall = new ApiGroup;
		$apiGroupCall->emulate($req->getPostFields()->getAll(), $request, $response);
		$apiGroupCall->run();

		return $apiGroupCall->httpResponse();*/

		$fields = $req->getPostFields()->getAll();
		$reqsData = json_decode($fields['data'], true);
		$responses = array();

		foreach($reqsData as $reqData) {
			$subreq = clone $req;
			foreach ($subreq->getPostFields() as $name => $value) {
				$subreq->removePostField($name);
			}
			$subreq->addPostFields($reqData);

			$responses[] = $this->executeApiCall($conn, $subreq)->content();
		}

		$resCtn = new ApiGroupResponse;
		$resCtn->setResponses($responses);

		$res = $this->getResponse($conn, $req);
		$res->addHeader('Content-Type: application/json');
		$res->setContent($resCtn);

		return $res;
	}

	protected function executeRawDataCall($conn, $req) {
		$request = $this->getRequest($conn, $req);
		$response = $this->getResponse($conn, $req);

		$dataCall = new RawDataCall();
		$dataCall->emulate(null, $request, $response);
		$dataCall->run();

		return $dataCall->httpResponse();
	}

	protected function executeReadFile($conn, $req, $matches) {
		$req->getQuery()->set('path', '/'.$matches[1].'/'.urldecode($matches[2]));

		return $this->executeRawDataCall($conn, $req);
	}

	protected function executeReadManifest($conn, $req) {
		$req->getQuery()->set('type', 'firefox');

		$request = $this->getRequest($conn, $req);
		$response = $this->getResponse($conn, $req);

		$manifestCall = new ManifestCall();
		$manifestCall->emulate(null, $request, $response);
		$manifestCall->run();

		return $manifestCall->httpResponse();
	}

	/**
	* @param string $langDef Input to convert
	* @return string
	* @see https://github.com/ratchetphp/Ratchet/blob/master/src/Ratchet/Session/SessionProvider.php
	*/
	protected function toClassCase($langDef) {
		return str_replace(' ', '', ucwords(str_replace('_', ' ', $langDef)));
	}
}