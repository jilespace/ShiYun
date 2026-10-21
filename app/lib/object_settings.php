<?php

declare(strict_types=1);

if (!defined('BASE_PATH')) {
    http_response_code(404);
    exit;
}

/* =========================================================================
   桶设置 XML 的解析与生成。
   所有用户输入写进 XML 前都经过 ENT_XML1 转义；解析一律 LIBXML_NONET。
   ========================================================================= */

function object_xml_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function object_xml_doc(string $xml): ?DOMDocument
{
    if (trim($xml) === '' || !class_exists('DOMDocument')) {
        return null;
    }
    $prev = libxml_use_internal_errors(true);
    $doc = new DOMDocument();
    $ok = $doc->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    return $ok ? $doc : null;
}

function object_xml_text(DOMElement $node, string $name): string
{
    foreach ($node->childNodes as $child) {
        if ($child instanceof DOMElement && $child->nodeName === $name) {
            return trim($child->textContent);
        }
    }
    return '';
}

function object_xml_child(DOMElement $node, string $name): ?DOMElement
{
    foreach ($node->childNodes as $child) {
        if ($child instanceof DOMElement && $child->nodeName === $name) {
            return $child;
        }
    }
    return null;
}

function object_versioning_parse(string $xml): string
{
    $doc = object_xml_doc($xml);
    if ($doc === null || $doc->documentElement === null) {
        return '';
    }
    $status = object_xml_text($doc->documentElement, 'Status');
    return in_array($status, ['Enabled', 'Suspended'], true) ? $status : '';
}

function object_versioning_xml(string $status): string
{
    if (!in_array($status, ['Enabled', 'Suspended'], true)) {
        throw new InvalidArgumentException('版本控制状态不合法。');
    }
    return '<?xml version="1.0" encoding="UTF-8"?><VersioningConfiguration><Status>'
        . $status . '</Status></VersioningConfiguration>';
}

function object_website_parse(string $xml): array
{
    $out = ['enabled' => false, 'index' => '', 'error' => ''];
    $doc = object_xml_doc($xml);
    if ($doc === null || $doc->documentElement === null) {
        return $out;
    }
    $out['enabled'] = true;
    $index = object_xml_child($doc->documentElement, 'IndexDocument');
    if ($index !== null) {
        $out['index'] = object_xml_text($index, 'Suffix');
    }
    $error = object_xml_child($doc->documentElement, 'ErrorDocument');
    if ($error !== null) {
        $out['error'] = object_xml_text($error, 'Key');
    }
    return $out;
}

function object_website_xml(string $index, string $error): string
{
    $index = trim($index);
    if ($index === '') {
        throw new InvalidArgumentException('索引文档不能为空。');
    }
    $xml = '<?xml version="1.0" encoding="UTF-8"?><WebsiteConfiguration>'
        . '<IndexDocument><Suffix>' . object_xml_escape($index) . '</Suffix></IndexDocument>';
    if (trim($error) !== '') {
        $xml .= '<ErrorDocument><Key>' . object_xml_escape(trim($error)) . '</Key></ErrorDocument>';
    }
    return $xml . '</WebsiteConfiguration>';
}

function object_cors_parse(string $xml): array
{
    $rules = [];
    $doc = object_xml_doc($xml);
    if ($doc === null || $doc->documentElement === null) {
        return $rules;
    }
    foreach ($doc->getElementsByTagName('CORSRule') as $node) {
        if (!$node instanceof DOMElement) {
            continue;
        }
        $rule = ['id' => '', 'origins' => [], 'methods' => [], 'headers' => [], 'expose' => [], 'max_age' => 0];
        foreach ($node->childNodes as $child) {
            if (!$child instanceof DOMElement) {
                continue;
            }
            $value = trim($child->textContent);
            switch ($child->nodeName) {
                case 'ID': $rule['id'] = $value; break;
                case 'AllowedOrigin': $rule['origins'][] = $value; break;
                case 'AllowedMethod': $rule['methods'][] = strtoupper($value); break;
                case 'AllowedHeader': $rule['headers'][] = $value; break;
                case 'ExposeHeader': $rule['expose'][] = $value; break;
                case 'MaxAgeSeconds': $rule['max_age'] = (int) $value; break;
            }
        }
        $rules[] = $rule;
    }
    return $rules;
}

function object_cors_xml(array $rules): string
{
    if ($rules === []) {
        throw new InvalidArgumentException('至少需要一条 CORS 规则。');
    }
    $allowed = ['GET', 'PUT', 'POST', 'DELETE', 'HEAD'];
    $xml = '<?xml version="1.0" encoding="UTF-8"?><CORSConfiguration>';
    foreach ($rules as $rule) {
        $origins = array_values(array_filter(array_map('trim', (array) ($rule['origins'] ?? []))));
        $methods = array_values(array_unique(array_map('strtoupper', (array) ($rule['methods'] ?? []))));
        if ($origins === [] || $methods === []) {
            throw new InvalidArgumentException('CORS 规则的来源与方法不能为空。');
        }
        foreach ($methods as $method) {
            if (!in_array($method, $allowed, true)) {
                throw new InvalidArgumentException('CORS 方法不合法：' . $method);
            }
        }
        $xml .= '<CORSRule>';
        $id = trim((string) ($rule['id'] ?? ''));
        if ($id !== '') {
            $xml .= '<ID>' . object_xml_escape($id) . '</ID>';
        }
        foreach ($origins as $origin) {
            $xml .= '<AllowedOrigin>' . object_xml_escape($origin) . '</AllowedOrigin>';
        }
        foreach ($methods as $method) {
            $xml .= '<AllowedMethod>' . $method . '</AllowedMethod>';
        }
        foreach ((array) ($rule['headers'] ?? []) as $header) {
            if (trim((string) $header) !== '') {
                $xml .= '<AllowedHeader>' . object_xml_escape((string) $header) . '</AllowedHeader>';
            }
        }
        foreach ((array) ($rule['expose'] ?? []) as $header) {
            if (trim((string) $header) !== '') {
                $xml .= '<ExposeHeader>' . object_xml_escape((string) $header) . '</ExposeHeader>';
            }
        }
        $maxAge = (int) ($rule['max_age'] ?? 0);
        if ($maxAge > 0) {
            $xml .= '<MaxAgeSeconds>' . $maxAge . '</MaxAgeSeconds>';
        }
        $xml .= '</CORSRule>';
    }
    return $xml . '</CORSConfiguration>';
}

/** @return array{0:bool,1:list<string>} */
function object_cors_allows(array $rules, string $origin, array $methods): array
{
    $origin = strtolower($origin);
    $methods = array_map('strtoupper', $methods);
    $needExpose = in_array('PUT', $methods, true) ? ['etag'] : [];
    $lastIssue = '未找到放行本站源 ' . $origin . ' 的规则';

    foreach ($rules as $rule) {
        $origins = array_map('strtolower', (array) ($rule['origins'] ?? []));
        $originOk = in_array('*', $origins, true) || in_array($origin, $origins, true);
        if (!$originOk) {
            foreach ($origins as $allowed) {
                if (str_contains($allowed, '*') && fnmatch($allowed, $origin)) {
                    $originOk = true;
                    break;
                }
            }
        }
        if (!$originOk) {
            continue;
        }
        $have = array_map('strtoupper', (array) ($rule['methods'] ?? []));
        $missing = array_values(array_diff($methods, $have));
        $expose = array_map('strtolower', (array) ($rule['expose'] ?? []));
        $missingExpose = array_values(array_filter(
            $needExpose,
            static fn (string $e): bool => !in_array($e, $expose, true)
        ));
        if ($missing === [] && $missingExpose === []) {
            return [true, []];
        }
        if ($missing !== []) {
            $lastIssue = '缺少方法：' . implode(', ', $missing);
        } else {
            $lastIssue = '缺少 ExposeHeader: ETag（分片上传需要读取 ETag）';
        }
    }
    return [false, [$lastIssue]];
}

function object_cors_merge_origin(array $rules, string $origin): array
{
    $origin = strtolower(trim($origin));
    foreach ($rules as $i => $rule) {
        $origins = array_map('strtolower', (array) ($rule['origins'] ?? []));
        if (in_array('*', $origins, true) || in_array($origin, $origins, true)) {
            $methods = array_map('strtoupper', (array) ($rule['methods'] ?? []));
            foreach (['GET', 'PUT', 'HEAD'] as $method) {
                if (!in_array($method, $methods, true)) {
                    $methods[] = $method;
                }
            }
            $expose = (array) ($rule['expose'] ?? []);
            if (!in_array('ETag', $expose, true)) {
                $expose[] = 'ETag';
            }
            $rules[$i]['methods'] = $methods;
            $rules[$i]['expose'] = $expose;
            return $rules;
        }
    }
    $rules[] = [
        'id' => 'shiyun-' . substr(hash('sha256', $origin), 0, 8),
        'origins' => [$origin],
        'methods' => ['GET', 'PUT', 'HEAD'],
        'headers' => ['*'],
        'expose' => ['ETag'],
        'max_age' => 3600,
    ];
    return $rules;
}

function object_lifecycle_validate(string $xml): void
{
    if (strlen($xml) > 256 * 1024) {
        throw new InvalidArgumentException('生命周期配置超过 256 KB。');
    }
    $doc = object_xml_doc($xml);
    if ($doc === null || $doc->documentElement === null
        || $doc->documentElement->nodeName !== 'LifecycleConfiguration') {
        throw new InvalidArgumentException('生命周期 XML 无法解析，根节点必须是 LifecycleConfiguration。');
    }
    if ($doc->getElementsByTagName('Rule')->length > 1000) {
        throw new InvalidArgumentException('生命周期规则不能超过 1000 条。');
    }
}

function object_lifecycle_rules_parse(string $xml): array
{
    $rules = [];
    $doc = object_xml_doc($xml);
    if ($doc === null || $doc->documentElement === null) {
        return $rules;
    }
    foreach ($doc->getElementsByTagName('Rule') as $node) {
        if (!$node instanceof DOMElement) {
            continue;
        }
        $filter = object_xml_child($node, 'Filter');
        $expiration = object_xml_child($node, 'Expiration');
        $transition = object_xml_child($node, 'Transition');
        $abort = object_xml_child($node, 'AbortIncompleteMultipartUpload');
        $rules[] = [
            'id'               => object_xml_text($node, 'ID'),
            'prefix'           => $filter !== null ? object_xml_text($filter, 'Prefix') : object_xml_text($node, 'Prefix'),
            'expire_days'      => $expiration !== null ? (int) object_xml_text($expiration, 'Days') : 0,
            'transition_days'  => $transition !== null ? (int) object_xml_text($transition, 'Days') : 0,
            'transition_class' => $transition !== null ? object_xml_text($transition, 'StorageClass') : '',
            'abort_days'       => $abort !== null ? (int) object_xml_text($abort, 'DaysAfterInitiation') : 0,
        ];
    }
    return $rules;
}

function object_lifecycle_xml(array $rules): string
{
    if ($rules === []) {
        throw new InvalidArgumentException('至少需要一条规则。');
    }
    if (count($rules) > 1000) {
        throw new InvalidArgumentException('生命周期规则不能超过 1000 条。');
    }
    $xml = '<?xml version="1.0" encoding="UTF-8"?><LifecycleConfiguration>';
    $index = 0;
    foreach ($rules as $rule) {
        $index++;
        $id = trim((string) ($rule['id'] ?? '')) ?: 'rule-' . $index;
        $prefix = (string) ($rule['prefix'] ?? '');
        $expire = (int) ($rule['expire_days'] ?? 0);
        $transitionDays = (int) ($rule['transition_days'] ?? 0);
        $transitionClass = strtoupper(trim((string) ($rule['transition_class'] ?? '')));
        $abort = (int) ($rule['abort_days'] ?? 0);

        if ($expire <= 0 && $transitionDays <= 0 && $abort <= 0) {
            throw new InvalidArgumentException('规则至少要有过期、转存储或清理分片中的一项。');
        }
        if ($transitionDays > 0 && !isset(object_storage_classes()[$transitionClass])) {
            throw new InvalidArgumentException('转存储类型不合法。');
        }

        $xml .= '<Rule><ID>' . object_xml_escape($id) . '</ID>'
            . '<Filter><Prefix>' . object_xml_escape($prefix) . '</Prefix></Filter>'
            . '<Status>Enabled</Status>';
        if ($transitionDays > 0) {
            $xml .= '<Transition><Days>' . $transitionDays . '</Days><StorageClass>'
                . $transitionClass . '</StorageClass></Transition>';
        }
        if ($expire > 0) {
            $xml .= '<Expiration><Days>' . $expire . '</Days></Expiration>';
        }
        if ($abort > 0) {
            $xml .= '<AbortIncompleteMultipartUpload><DaysAfterInitiation>'
                . $abort . '</DaysAfterInitiation></AbortIncompleteMultipartUpload>';
        }
        $xml .= '</Rule>';
    }
    return $xml . '</LifecycleConfiguration>';
}

function object_tagging_parse(string $xml): array
{
    $tags = [];
    $doc = object_xml_doc($xml);
    if ($doc === null || $doc->documentElement === null) {
        return $tags;
    }
    foreach ($doc->getElementsByTagName('Tag') as $node) {
        if (!$node instanceof DOMElement) {
            continue;
        }
        $tags[] = ['key' => object_xml_text($node, 'Key'), 'value' => object_xml_text($node, 'Value')];
    }
    return $tags;
}

function object_tagging_xml(array $tags): string
{
    $xml = '<?xml version="1.0" encoding="UTF-8"?><Tagging><TagSet>';
    foreach ($tags as $tag) {
        $key = trim((string) ($tag['key'] ?? ''));
        if ($key === '') {
            continue;
        }
        $xml .= '<Tag><Key>' . object_xml_escape($key) . '</Key><Value>'
            . object_xml_escape((string) ($tag['value'] ?? '')) . '</Value></Tag>';
    }
    return $xml . '</TagSet></Tagging>';
}

function object_acl_parse(string $xml): array
{
    $grants = [];
    $canned = 'private';
    $doc = object_xml_doc($xml);
    if ($doc === null || $doc->documentElement === null) {
        return ['canned' => $canned, 'grants' => $grants];
    }
    foreach ($doc->getElementsByTagName('Grant') as $node) {
        if (!$node instanceof DOMElement) {
            continue;
        }
        $grantee = object_xml_child($node, 'Grantee');
        $name = '';
        $type = 'CanonicalUser';
        if ($grantee !== null) {
            $type = $grantee->getAttribute('xsi:type') ?: 'CanonicalUser';
            $name = object_xml_text($grantee, 'DisplayName')
                ?: object_xml_text($grantee, 'URI')
                ?: object_xml_text($grantee, 'ID');
        }
        $permission = object_xml_text($node, 'Permission');
        $grants[] = ['type' => $type, 'name' => $name, 'permission' => $permission];
        if (str_contains($name, 'AllUsers') && in_array($permission, ['READ', 'FULL_CONTROL'], true)) {
            $canned = 'public-read';
        }
    }
    return ['canned' => $canned, 'grants' => $grants];
}
