<?php
namespace AdamAveray\SlimExtensions\Http;

class Response extends \Slim\Http\Response {
	private $isDebug = false;

	/**
	 * @param bool $isDebug
	 * @return $this
	 */
	public function setIsDebug($isDebug){
		$this->isDebug = (bool)$isDebug;
		return $this;
	}

	public function api($data, array $extra = null, $status = null){
		$json = array_merge((array)$extra, [
			'_status' => (isset($status) ? $status : $this->getStatusCode()),
		]);
		if(isset($data)){
			$json['data'] = $data;
		}

		return $this->withJson($json, $status);
	}

	public function withCSV(array $headers, array $data, $status = null){
		$stream = fopen('php://temp', 'r+');
		$response = $this->withBody(new Body($stream));
		fputcsv($stream, $headers);
		foreach($data as $row){
			fputcsv($stream, $row);
		}

		$response = $response->withHeader('Content-Type', 'text/csv;charset=utf-8');
		if(isset($status)){
			$response = $response->withStatus($status);
		}
		return $response;
	}

	/** {@inheritdoc} */
	public function withJson($data, $status = null, $encodingOptions = 0){
		if($this->isDebug){
			$encodingOptions = \JSON_PRETTY_PRINT;
		}

		return parent::withJson($data, $status, $encodingOptions);
	}
}
