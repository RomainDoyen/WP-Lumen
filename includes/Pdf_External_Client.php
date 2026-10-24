<?php

declare(strict_types=1);

namespace LumenWp;

/**
 * Envoie un PDF à l’API tierce configurée dans les réglages.
 */
final class Pdf_External_Client
{
	public function is_configured(): bool
	{
		$url = trim((string) (Plugin::instance()->settings()['pdf_external_api_url'] ?? ''));

		return $url !== '' && wp_http_validate_url($url) !== false;
	}

	/**
	 * @return array{success: bool, output_path?: string, bytes_saved?: int, message?: string}
	 */
	public function compress(string $input_path, string $output_path): array
	{
		$url = trim((string) (Plugin::instance()->settings()['pdf_external_api_url'] ?? ''));
		if ($url === '' || wp_http_validate_url($url) === false) {
			return [
				'success' => false,
				'message' => __('URL API PDF externe non configurée.', 'lumen-wp'),
			];
		}
		if (! is_readable($input_path)) {
			return [
				'success' => false,
				'message' => __('Fichier PDF source introuvable.', 'lumen-wp'),
			];
		}

		$body = file_get_contents($input_path);
		if ($body === false) {
			return [
				'success' => false,
				'message' => __('Impossible de lire le PDF source.', 'lumen-wp'),
			];
		}

		$boundary = wp_generate_password(16, false);
		$filename = basename($input_path);
		$payload  = "--{$boundary}\r\n"
			. "Content-Disposition: form-data; name=\"file\"; filename=\"{$filename}\"\r\n"
			. "Content-Type: application/pdf\r\n\r\n"
			. $body . "\r\n"
			. "--{$boundary}--\r\n";

		$headers = [
			'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
		];
		$api_key = Api_Key_Encryption::decrypt((string) (Plugin::instance()->settings()['pdf_external_api_key'] ?? ''));
		if ($api_key !== '') {
			$headers['Authorization'] = 'Bearer ' . $api_key;
		}

		$response = wp_remote_post($url, [
			'timeout' => 120,
			'headers' => $headers,
			'body'    => $payload,
		]);

		if (is_wp_error($response)) {
			return [
				'success' => false,
				'message' => $response->get_error_message(),
			];
		}

		$code = (int) wp_remote_retrieve_response_code($response);
		$raw  = (string) wp_remote_retrieve_body($response);
		if ($code < 200 || $code >= 300) {
			return [
				'success' => false,
				'message' => sprintf(
					/* translators: 1: HTTP code, 2: short response excerpt */
					__('API PDF externe — erreur HTTP %1$d : %2$s', 'lumen-wp'),
					$code,
					function_exists('mb_substr') ? mb_substr($raw, 0, 180) : substr($raw, 0, 180)
				),
			];
		}

		$type = (string) wp_remote_retrieve_header($response, 'content-type');
		if (strpos($type, 'application/pdf') !== false) {
			return $this->store_pdf($raw, $output_path, $input_path);
		}

		$json = json_decode($raw, true);
		if (is_array($json)) {
			if (! empty($json['url']) && is_string($json['url']) && wp_http_validate_url($json['url'])) {
				$downloaded = wp_remote_get($json['url'], ['timeout' => 120]);
				if (is_wp_error($downloaded)) {
					return ['success' => false, 'message' => $downloaded->get_error_message()];
				}

				return $this->store_pdf((string) wp_remote_retrieve_body($downloaded), $output_path, $input_path);
			}
			if (! empty($json['file_base64']) && is_string($json['file_base64'])) {
				$decoded = base64_decode($json['file_base64'], true);
				if ($decoded !== false) {
					return $this->store_pdf($decoded, $output_path, $input_path);
				}
			}
		}

		return [
			'success' => false,
			'message' => __('Réponse API PDF non reconnue (attendu PDF, URL ou base64).', 'lumen-wp'),
		];
	}

	/**
	 * @return array{success: bool, output_path?: string, bytes_saved?: int, message?: string}
	 */
	private function store_pdf(string $bytes, string $output_path, string $input_path): array
	{
		if (strncmp($bytes, '%PDF', 4) !== 0) {
			return [
				'success' => false,
				'message' => __('La réponse n’est pas un PDF.', 'lumen-wp'),
			];
		}
		if (file_put_contents($output_path, $bytes) === false) {
			return [
				'success' => false,
				'message' => __('Impossible d’écrire le PDF compressé.', 'lumen-wp'),
			];
		}

		$old = (int) (@filesize($input_path) ?: 0);
		$new = (int) (@filesize($output_path) ?: 0);

		return [
			'success'     => true,
			'output_path' => $output_path,
			'bytes_saved' => max(0, $old - $new),
		];
	}
}
