<?php
declare(strict_types=1);

namespace MCMA\Core\Storage;

use RuntimeException;

final class AzureBlobStorageAdapter implements StorageAdapter
{
    /** @var null|callable */
    private $requester;
    private string $endpoint;
    private string $sasQuery;

    public function __construct(
        private readonly string $account,
        private readonly string $container,
        private readonly string $prefix = '',
        ?string $sasToken = null,
        private readonly ?string $bearerToken = null,
        ?string $endpoint = null,
        ?callable $requester = null
    ) {
        if (!preg_match('/^[a-z0-9]{3,24}$/', $this->account)) throw new RuntimeException('Invalid Azure Storage account name');
        if (!preg_match('/^[a-z0-9](?:[a-z0-9-]{1,61}[a-z0-9])?$/', $this->container)) throw new RuntimeException('Invalid Azure Blob container name');
        self::validatePrefix($this->prefix);

        $this->endpoint = rtrim($endpoint ?? ('https://' . $this->account . '.blob.core.windows.net'), '/');
        $parts = parse_url($this->endpoint);
        if (!is_array($parts) || !in_array($parts['scheme'] ?? null, ['http','https'], true) || empty($parts['host'])) {
            throw new RuntimeException('Invalid Azure Blob endpoint');
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new RuntimeException('Azure Blob endpoint must not contain credentials, query or fragment');
        }

        $this->sasQuery = ltrim(trim((string)$sasToken), '?');
        if ($requester === null && $this->sasQuery === '' && ($this->bearerToken ?? '') === '') {
            throw new RuntimeException('Azure Blob SAS token or bearer token is required');
        }
        $this->requester = $requester;
    }

    public function id(): string
    {
        return 'azure:' . $this->account . '/' . $this->container . '/' . trim($this->prefix, '/');
    }

    public function get(string $locator): array
    {
        [$status,$body,$headers]=$this->request('GET',$this->blobUrl($this->blobName($locator)));
        if($status===404) throw new RuntimeException('Azure Blob object not found: '.$locator);
        if($status!==200) throw new RuntimeException('Azure Blob read failed: HTTP '.$status);
        return ['bytes'=>$body,'version'=>$this->etag($headers)];
    }

    public function exists(string $locator): bool
    {
        [$status]=$this->request('HEAD',$this->blobUrl($this->blobName($locator)));
        if($status===200) return true;
        if($status===404) return false;
        throw new RuntimeException('Azure Blob HEAD failed: HTTP '.$status);
    }

    public function put(string $locator, string $bytes, ?string $expectedVersion = null, bool $createOnly = false): string
    {
        $headers=[
            'x-ms-blob-type'=>'BlockBlob',
            'content-type'=>'application/octet-stream',
        ];
        if($createOnly) $headers['if-none-match']='*';
        elseif($expectedVersion!==null) $headers['if-match']=self::quoteEtag($expectedVersion);

        [$status,,$responseHeaders]=$this->request('PUT',$this->blobUrl($this->blobName($locator)),$bytes,$headers);
        if(in_array($status,[409,412],true)) throw new RuntimeException('Azure Blob version conflict: '.$locator);
        if(!in_array($status,[200,201],true)) throw new RuntimeException('Azure Blob write failed: HTTP '.$status);
        return $this->etag($responseHeaders);
    }

    public function delete(string $locator, ?string $expectedVersion = null): void
    {
        $headers=[];
        if($expectedVersion!==null) $headers['if-match']=self::quoteEtag($expectedVersion);
        [$status]=$this->request('DELETE',$this->blobUrl($this->blobName($locator)),'',$headers);
        if($status===412) throw new RuntimeException('Azure Blob version conflict: '.$locator);
        if(!in_array($status,[202,204,404],true)) throw new RuntimeException('Azure Blob delete failed: HTTP '.$status);
    }

    public function list(string $prefix = ''): array
    {
        $relativePrefix=self::cleanLocator($prefix,true);
        $fullPrefix=$this->blobName($relativePrefix,true);
        $marker='';
        $out=[];

        do {
            $query=[
                'restype'=>'container',
                'comp'=>'list',
                'prefix'=>$fullPrefix,
            ];
            if($marker!=='') $query['marker']=$marker;
            [$status,$body]=$this->request('GET',$this->containerUrl($query));
            if($status!==200) throw new RuntimeException('Azure Blob list failed: HTTP '.$status);

            foreach(self::xmlTagValues($body,'Name') as $name){
                $relative=$this->relativeName($name);
                if($relative!==null && $relative!=='' && ($relativePrefix===''||str_starts_with($relative,$relativePrefix))) $out[]=$relative;
            }
            $marker=self::xmlTagValue($body,'NextMarker') ?? '';
        } while($marker!=='');

        sort($out,SORT_STRING);
        return array_values(array_unique($out));
    }

    public function withWriteLock(callable $callback): mixed { return $callback(); }

    public function capabilities(): array
    {
        return [
            'atomic_put'=>true,
            'compare_and_swap'=>true,
            'exclusive_lock'=>false,
            'conditional_create'=>true,
            'conditional_delete'=>true,
            'list_prefix'=>true,
            'byte_preserving'=>true,
            'version'=>'etag',
        ];
    }

    private function blobName(string $locator, bool $allowEmpty=false): string
    {
        $locator=self::cleanLocator($locator,$allowEmpty);
        $base=trim($this->prefix,'/');
        if($base==='') return $locator;
        return $locator==='' ? $base.'/' : $base.'/'.$locator;
    }

    private function relativeName(string $name): ?string
    {
        $base=trim($this->prefix,'/');
        if($base==='') return $name;
        if($name===$base) return '';
        if(!str_starts_with($name,$base.'/')) return null;
        return substr($name,strlen($base)+1);
    }

    private function blobUrl(string $name): string
    {
        $segments=array_map('rawurlencode',explode('/',$name));
        return $this->appendAuth($this->endpoint.'/'.rawurlencode($this->container).'/'.implode('/',$segments));
    }

    private function containerUrl(array $query): string
    {
        return $this->appendAuth($this->endpoint.'/'.rawurlencode($this->container),$query);
    }

    private function appendAuth(string $url, array $query=[]): string
    {
        $q=http_build_query($query,'','&',PHP_QUERY_RFC3986);
        if($this->sasQuery!=='') $q=$q===''?$this->sasQuery:$q.'&'.$this->sasQuery;
        return $q===''?$url:$url.'?'.$q;
    }

    /** @return array{0:int,1:string,2:array<string,string>} */
    private function request(string $method,string $url,string $body='',array $headers=[]): array
    {
        $headers=array_change_key_case($headers,CASE_LOWER);
        $headers['x-ms-version']='2023-11-03';
        $headers['x-ms-date']=gmdate('D, d M Y H:i:s').' GMT';
        if(($this->bearerToken??'')!=='') $headers['authorization']='Bearer '.$this->bearerToken;

        if($this->requester!==null){
            $result=($this->requester)(strtoupper($method),$url,$headers,$body);
            if(!is_array($result)||count($result)<2) throw new RuntimeException('Invalid Azure Blob requester result');
            return [(int)$result[0],(string)$result[1],is_array($result[2]??null)?$result[2]:[]];
        }
        if(!function_exists('curl_init')) throw new RuntimeException('PHP cURL extension is required for Azure Blob');

        $responseHeaders=[];$ch=curl_init($url);$wire=[];
        foreach($headers as $name=>$value)$wire[]=$name.': '.$value;
        curl_setopt_array($ch,[
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_CUSTOMREQUEST=>strtoupper($method),
            CURLOPT_HTTPHEADER=>$wire,
            CURLOPT_TIMEOUT=>60,
            CURLOPT_HEADERFUNCTION=>static function($ch,string $line)use(&$responseHeaders):int{
                $len=strlen($line);$pos=strpos($line,':');
                if($pos!==false)$responseHeaders[strtolower(trim(substr($line,0,$pos)))]=trim(substr($line,$pos+1));
                return $len;
            },
        ]);
        if(strtoupper($method)==='HEAD') curl_setopt($ch,CURLOPT_NOBODY,true);
        if(in_array(strtoupper($method),['PUT','POST','PATCH'],true)) curl_setopt($ch,CURLOPT_POSTFIELDS,$body);
        $responseBody=curl_exec($ch);
        if($responseBody===false){$error=curl_error($ch);curl_close($ch);throw new RuntimeException('Azure Blob HTTP error: '.$error);}
        $status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);
        return [$status,(string)$responseBody,$responseHeaders];
    }

    private function etag(array $headers): string
    {
        $etag=$headers['etag']??null;
        if(!is_string($etag)||trim($etag)==='') throw new RuntimeException('Azure Blob response did not include ETag');
        $etag=trim($etag);
        if(str_starts_with($etag,'W/'))$etag=substr($etag,2);
        return trim($etag,'"');
    }

    private static function quoteEtag(string $etag): string
    {
        $etag=trim($etag," \t\n\r\0\x0B\"");
        if($etag===''||str_contains($etag,'"')) throw new RuntimeException('Invalid Azure Blob ETag');
        return '"'.$etag.'"';
    }

    private static function xmlTagValues(string $xml,string $tag): array
    {
        $q=preg_quote($tag,'/');
        if(!preg_match_all('/<'.$q.'>(.*?)<\\/'.$q.'>/s',$xml,$m)) return [];
        return array_map(static fn(string $v):string=>html_entity_decode(trim($v),ENT_QUOTES|ENT_XML1,'UTF-8'),$m[1]);
    }

    private static function xmlTagValue(string $xml,string $tag): ?string
    {
        $v=self::xmlTagValues($xml,$tag); return $v[0]??null;
    }

    private static function validatePrefix(string $prefix): void
    {
        $prefix=trim(str_replace('\\','/',$prefix),'/');
        if($prefix!==''&&(str_contains($prefix,'..')||str_contains($prefix,"\0")||!preg_match('#^[A-Za-z0-9._/-]+$#',$prefix))) {
            throw new RuntimeException('Invalid Azure Blob prefix');
        }
    }

    private static function cleanLocator(string $locator,bool $allowEmpty=false): string
    {
        $locator=trim(str_replace('\\','/',$locator),'/');
        if($allowEmpty&&$locator==='') return '';
        if($locator===''||str_contains($locator,'..')||str_contains($locator,"\0")||!preg_match('#^[A-Za-z0-9._/-]+$#',$locator)) {
            throw new RuntimeException('Invalid Azure Blob locator');
        }
        return $locator;
    }
}
