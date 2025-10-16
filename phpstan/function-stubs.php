<?php
/**
 * @param array<string,mixed> $options
 * @return array{
 *   ok: bool,
 *   status: int,
 *   headers: array<string,string>,
 *   body: string,
 *   final_url: string,
 *   from_cache: bool,
 *   was_304: bool,
 *   error: ?string
 * }
 */
function http_get(string $url, array $options = []): array {}

/**
 * @param array<string,mixed> $options
 * @return array{
 *   ok: bool,
 *   status: int,
 *   headers: array<string,string>,
 *   body: string,
 *   final_url: string,
 *   from_cache: bool,
 *   was_304: bool,
 *   error: ?string
 * }
 */
function http_head(string $url, array $options = []): array {}

/**
 * @param array<int,array<string,mixed>> $requests
 * @param array<string,mixed> $options
 * @return array{
 *   ok: bool,
 *   status: int,
 *   responses: array<int, array{
 *     ok: bool,
 *     status: int,
 *     headers: array<string,string>,
 *     body: string,
 *     final_url: string,
 *     from_cache: bool,
 *     was_304: bool,
 *     error: ?string
 *   }>
 * }
 */
function http_multi_get(array $requests, array $options = []): array {}

/**
 * @param array<string,mixed> $span
 * @param string $stage
 * @param array<string,mixed> $meta
 */
function sfm_log_error(array $span, string $stage, array $meta = []): void {}
