<?php
declare(strict_types=1);

namespace MCMA\Core\Cli;

use MCMA\Core\Crypto;
use MCMA\Core\HistoricalCrypto;
use MCMA\Core\KeyStore;
use MCMA\Core\Library;
use MCMA\Core\Agent\Librarian;
use MCMA\Core\Ask\AskService;
use MCMA\Core\Ask\GenerationProvider;
use MCMA\Core\Knowledge\KnowledgeService;
use MCMA\Core\Semantic\EmbeddingProvider;
use MCMA\Core\Semantic\SemanticIndexService;
use MCMA\Core\Storage\StorageAdapter;
use MCMA\Core\Storage\StorageFactory;
use MCMA\Core\Storage\StorageMigrator;
use Throwable;

final class CliApplication
{
    public function __construct(private readonly ProviderFactory $providers = new ProviderFactory())
    {
    }

    public function run(array $argv): int
    {
        try {
            $this->execute($argv);
            return 0;
        } catch (CliException $e) {
            if ($e->isUsage()) fwrite(STDERR, $e->getMessage());
            else fwrite(STDERR, 'Error: ' . $e->getMessage() . PHP_EOL);
            return $e->exitCode();
        } catch (Throwable $e) {
            fwrite(STDERR, 'Error: ' . $e->getMessage() . PHP_EOL);
            return 1;
        }
    }

    private function execute(array $argv): void
    {
    $args=$argv;array_shift($args);$command=array_shift($args)??null;
    if($command===null||in_array($command,['help','-h','--help'],true)) $this->usage();

    switch($command){
        case 'init': {
            $loc=array_shift($args)??$this->usage();$opts=$this->parseOptions($args);
            $lib=Library::init($this->storage($loc),$opts['mode']??'private');$info=$lib->info();
            echo "Initialized MCMA 1.0 library\n".$this->pretty($info).PHP_EOL;
            if(getenv('MCMA_MASTER_KEY_B64')===false||trim((string)getenv('MCMA_MASTER_KEY_B64'))==='') echo 'Local key: '.KeyStore::keyPath($info['library_id']).PHP_EOL;
            break;
        }
        case 'open': {
            $loc=array_shift($args)??$this->usage();if($args!==[])$this->usage();$v=$this->library($loc)->verify();
            echo 'Opened '.$v['library_id'].' on '.$v['storage'].PHP_EOL.'Verified indexed objects: '.$v['objects_verified'].PHP_EOL;break;
        }
        case 'info': {
            $loc=array_shift($args)??$this->usage();if($args!==[])$this->usage();echo $this->pretty($this->library($loc)->info()).PHP_EOL;break;
        }
        case 'write': {
            $loc=array_shift($args)??$this->usage();$uri=array_shift($args)??$this->usage();$input=array_shift($args)??$this->usage();$opts=$this->parseOptions($args);
            $format=$opts['format']??'text';
            echo $this->pretty($this->library($loc)->writeAs($this->actor($opts),$uri,$this->readInput($input,$format),$format,$opts['temperature']??'hot',$opts['layer']??'40-semantic',$opts['scope']??'user',$opts['maturity']??'raw')).PHP_EOL;break;
        }
        case 'update': {
            $loc=array_shift($args)??$this->usage();$uri=array_shift($args)??$this->usage();$input=array_shift($args)??$this->usage();$opts=$this->parseOptions($args);
            $lib=$this->library($loc);$current=$lib->readAs($this->actor($opts),$uri);$format=$opts['format']??(string)($current['payload']['content_format']??'text');
            echo $this->pretty($lib->updateAs($this->actor($opts),$uri,$this->readInput($input,$format),$opts['format']??null,$opts['temperature']??null,$opts['layer']??null,$opts['scope']??null,$opts['maturity']??null)).PHP_EOL;break;
        }
        case 'temperature': {
            $loc=array_shift($args)??$this->usage();$uri=array_shift($args)??$this->usage();$temp=array_shift($args)??$this->usage();$opts=$this->parseOptions($args);
            echo $this->pretty($this->library($loc)->setTemperatureAs($this->actor($opts),$uri,strtolower($temp))).PHP_EOL;break;
        }
        case 'read': {
            $loc=array_shift($args)??$this->usage();$uri=array_shift($args)??$this->usage();$opts=$this->parseOptions($args);
            $p=$this->library($loc)->readAs($this->actor($opts),$uri)['payload'];$f=$p['content_format']??'json';$content=$p['content']??null;
            if($f==='binary'&&is_string($content))fwrite(STDOUT,Crypto::b64uDecode($content));
            elseif($f==='json')echo $this->pretty($content).PHP_EOL;
            elseif(is_string($content)){echo $content;if(!str_ends_with($content,"\n"))echo PHP_EOL;}
            else echo $this->pretty($content).PHP_EOL;
            break;
        }
        case 'verify': {
            $loc=array_shift($args)??$this->usage();if($args!==[])$this->usage();echo $this->pretty($this->library($loc)->verify()).PHP_EOL;break;
        }
        case 'list': {
            $loc=array_shift($args)??$this->usage();$opts=$this->parseOptions($args);
            foreach($this->library($loc)->listAs($this->actor($opts)) as $entry) echo ($entry['logical_refs'][0]??'(no-ref)')."\t".$entry['object_id']."\t".($entry['temperature']??'').PHP_EOL;
            break;
        }
        case 'tree': {
            $loc=array_shift($args)??$this->usage();$opts=$this->parseOptions($args);echo "memory://\n";$rendered=$this->renderTree($this->library($loc)->treeAs($this->actor($opts)));if($rendered!=='')echo $rendered.PHP_EOL;break;
        }

        case 'access-init': {
            $loc=array_shift($args)??$this->usage();$opts=$this->parseOptions($args);$policy=isset($opts['policy'])?$this->readJsonObject($opts['policy']):null;
            echo $this->pretty($this->library($loc)->initializeAccessControl($policy,$this->actor($opts))).PHP_EOL;break;
        }
        case 'access-check': {
            $loc=array_shift($args)??$this->usage();$who=array_shift($args)??$this->usage();$action=array_shift($args)??$this->usage();$resource=array_shift($args)??$this->usage();if($args!==[])$this->usage();
            echo $this->pretty($this->library($loc)->permissionDecision($who,$action,$resource)).PHP_EOL;break;
        }
        case 'permissions-show': {
            $loc=array_shift($args)??$this->usage();$opts=$this->parseOptions($args);echo $this->pretty($this->library($loc)->permissions($this->actor($opts))).PHP_EOL;break;
        }
        case 'permissions-set': {
            $loc=array_shift($args)??$this->usage();$file=array_shift($args)??$this->usage();$opts=$this->parseOptions($args);
            echo $this->pretty($this->library($loc)->setPermissions($this->readJsonObject($file),$this->actor($opts))).PHP_EOL;break;
        }
        case 'vault-put': {
            $loc=array_shift($args)??$this->usage();$name=array_shift($args)??$this->usage();$env=array_shift($args)??$this->usage();$opts=$this->parseOptions($args);
            $secret=$this->envSecret($env);
            try{echo $this->pretty($this->library($loc)->vaultPut($name,$secret,$opts['type']??'secret',$this->actor($opts))).PHP_EOL;}
            finally{$secret=str_repeat("\0",strlen($secret));}
            break;
        }
        case 'vault-list': {
            $loc=array_shift($args)??$this->usage();$opts=$this->parseOptions($args);echo $this->pretty($this->library($loc)->vaultList($this->actor($opts))).PHP_EOL;break;
        }
        case 'vault-delete': {
            $loc=array_shift($args)??$this->usage();$name=array_shift($args)??$this->usage();$opts=$this->parseOptions($args);echo $this->pretty($this->library($loc)->vaultDelete($name,$this->actor($opts))).PHP_EOL;break;
        }

        case 'knowledge-put': {
            $loc=array_shift($args)??$this->usage();$questionFile=array_shift($args)??$this->usage();$answerFile=array_shift($args)??$this->usage();$opts=$this->parseOptions($args);
            $question=$this->readTextFile($questionFile);$format=$opts['answer-format']??'text';
            $answer=$format==='json'?$this->readJsonValue($answerFile):$this->readInput($answerFile,$format);
            $provenance=isset($opts['provenance'])?$this->readJsonArray($opts['provenance']):[];
            $freshness=$opts['freshness']??'stable';
            $maxAge=$freshness==='immutable'?null:(isset($opts['max-age'])?(int)$opts['max-age']:2592000);
            $service=new KnowledgeService($this->library($loc));
            echo $this->pretty($service->capture($this->actor($opts),$question,$answer,$format,(float)($opts['confidence']??0.5),$opts['validation']??'unverified',$provenance,$freshness,$maxAge,$opts['reuse']??'reuse-unless-stale')).PHP_EOL;
            break;
        }
        case 'knowledge-check': {
            $loc=array_shift($args)??$this->usage();$questionFile=array_shift($args)??$this->usage();$opts=$this->parseOptions($args);
            $service=new KnowledgeService($this->library($loc));
            echo $this->pretty($service->directAnswer($this->actor($opts),$this->readTextFile($questionFile),$this->yesNo($opts,'current',false),(float)($opts['min-confidence']??0.75))).PHP_EOL;
            break;
        }
        case 'knowledge-show': {
            $loc=array_shift($args)??$this->usage();$questionFile=array_shift($args)??$this->usage();$opts=$this->parseOptions($args);
            echo $this->pretty((new KnowledgeService($this->library($loc)))->inspect($this->actor($opts),$this->readTextFile($questionFile))).PHP_EOL;
            break;
        }
        case 'knowledge-validate': {
            $loc=array_shift($args)??$this->usage();$questionFile=array_shift($args)??$this->usage();$state=array_shift($args)??$this->usage();$confidence=array_shift($args)??$this->usage();$reasonFile=array_shift($args)??$this->usage();$opts=$this->parseOptions($args);
            $provenance=isset($opts['provenance'])?$this->readJsonArray($opts['provenance']):[];
            echo $this->pretty((new KnowledgeService($this->library($loc)))->validateKnowledge($this->actor($opts),$this->readTextFile($questionFile),$state,(float)$confidence,$this->readTextFile($reasonFile),$provenance)).PHP_EOL;
            break;
        }

        case 'semantic-index': {
            $loc=array_shift($args)??$this->usage();$opts=$this->parseOptions($args);
            $service=new SemanticIndexService($this->library($loc));
            echo $this->pretty($service->indexAll($this->embeddingProvider($opts),$this->actor($opts))).PHP_EOL;
            break;
        }
        case 'semantic-check': {
            $loc=array_shift($args)??$this->usage();$questionFile=array_shift($args)??$this->usage();$opts=$this->parseOptions($args);
            $service=new SemanticIndexService($this->library($loc));
            echo $this->pretty($service->answer(
                $this->actor($opts),
                $this->readTextFile($questionFile),
                $this->embeddingProvider($opts),
                $this->yesNo($opts,'current',false),
                (float)($opts['min-confidence']??0.75),
                (float)($opts['min-similarity']??0.78),
                (int)($opts['top-k']??5)
            )).PHP_EOL;
            break;
        }
        case 'semantic-topk': {
            $loc=array_shift($args)??$this->usage();$questionFile=array_shift($args)??$this->usage();$opts=$this->parseOptions($args);
            $service=new SemanticIndexService($this->library($loc));
            echo $this->pretty($service->topK(
                $this->actor($opts),
                $this->readTextFile($questionFile),
                $this->embeddingProvider($opts),
                $this->yesNo($opts,'current',false),
                (float)($opts['min-confidence']??0.75),
                (float)($opts['min-similarity']??0.78),
                (int)($opts['top-k']??5)
            )).PHP_EOL;
            break;
        }
        case 'ask': {
            $loc=array_shift($args)??$this->usage();$questionFile=array_shift($args)??$this->usage();$opts=$this->parseOptions($args);
            $lib=$this->library($loc);
            $knowledge=new KnowledgeService($lib);
            $embedding=$this->optionalEmbeddingProvider($opts);
            $semantic=$embedding!==null?new SemanticIndexService($lib):null;
            $librarian=$embedding!==null?new Librarian($knowledge,$semantic,$embedding):new Librarian($knowledge);
            $generator=$this->generationProvider($opts);
            $service=new AskService($knowledge,$semantic,$embedding,$generator,$librarian);
            $captureFreshness=$opts['capture-freshness']??'stable';
            $captureMaxAge=$captureFreshness==='immutable'?null:(isset($opts['capture-max-age'])?(int)$opts['capture-max-age']:2592000);
            $captureProvenance=isset($opts['capture-provenance'])?$this->readJsonArray($opts['capture-provenance']):[];
            echo $this->pretty($service->ask(
                $opts['actor']??'ai',
                $this->readTextFile($questionFile),
                $this->yesNo($opts,'current',false),
                (float)($opts['min-confidence']??0.75),
                (float)($opts['min-similarity']??0.78),
                (int)($opts['top-k']??5),
                $this->yesNo($opts,'remember',true),
                [
                    'confidence'=>(float)($opts['capture-confidence']??0.5),
                    'validation_state'=>$opts['capture-validation']??'unverified',
                    'freshness_class'=>$captureFreshness,
                    'max_age_seconds'=>$captureMaxAge,
                    'reuse_policy'=>$opts['capture-reuse']??'reuse-unless-stale',
                    'provenance'=>$captureProvenance,
                ]
            )).PHP_EOL;
            break;
        }

        case 'key-export': {
            $loc=array_shift($args)??$this->usage();$out=array_shift($args)??$this->usage();$opts=$this->parseOptions($args);$lib=$this->library($loc);
            echo $this->pretty(KeyStore::exportRecovery($lib->libraryId(),$out,$this->recoveryPassphrase($opts))).PHP_EOL;break;
        }
        case 'key-import': {
            $in=array_shift($args)??$this->usage();$opts=$this->parseOptions($args);echo $this->pretty(KeyStore::importRecovery($in,$this->recoveryPassphrase($opts),($opts['replace']??'no')==='yes')).PHP_EOL;break;
        }
        case 'migrate': {
            $loc=array_shift($args)??$this->usage();$source=array_shift($args)??$this->usage();$uri=array_shift($args)??$this->usage();$opts=$this->parseOptions($args);
            $legacyEnv=$opts['legacy-key-env']??'MCMA_LEGACY_MASTER_KEY_B64';if(!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/',$legacyEnv))$this->fail('Invalid legacy-key environment variable name');
            $h=HistoricalCrypto::readAndDecrypt($source,HistoricalCrypto::loadLegacyMasterKey($opts['legacy-key-file']??null,$legacyEnv));$format=$opts['format']??'text';$content=$h['plaintext'];
            if($format==='json')$content=json_decode($content,true,512,JSON_THROW_ON_ERROR);
            echo $this->pretty($this->library($loc)->importHistorical($uri,$content,$h['envelope'],$format,$opts['temperature']??'cold',$opts['layer']??'40-semantic',$opts['scope']??'user',$opts['maturity']??'observed',$opts['source-ref']??basename($source))).PHP_EOL;break;
        }
        case 'storage-copy': {
            $source=array_shift($args)??$this->usage();$dest=array_shift($args)??$this->usage();if($args!==[])$this->usage();echo $this->pretty(StorageMigrator::copy($this->storage($source),$this->storage($dest))).PHP_EOL;break;
        }
        default: $this->usage();
    }
    }

    private function fail(string $message, int $code = 1): never
    {
        throw new CliException($message, $code);
    }

    private function usage(): never
    {
        throw new CliException($this->usageText(), 2, true);
    }

    private function usageText(): string
    {
        return <<<'TXT'
MCMA 1.0 CLI

LOCATION:
  /local/path
  github://OWNER/REPO/optional/prefix?branch=main
  s3://BUCKET/optional/prefix?region=us-east-1
  gcs://BUCKET/optional/prefix
  gdrive://ROOT_FOLDER_ID
  azure://ACCOUNT/CONTAINER/optional/prefix
  oss://BUCKET/optional/prefix?region=cn-hangzhou
  webdav+https://HOST/existing/library/root

Usage:
  mcma init LOCATION [--mode=private|normal]
  mcma open LOCATION
  mcma info LOCATION
  mcma write LOCATION MEMORY_URI INPUT [options] [--actor=owner]
  mcma update LOCATION MEMORY_URI INPUT [options] [--actor=owner]
  mcma temperature LOCATION MEMORY_URI hot|warm|cold|frozen [--actor=owner]
  mcma read LOCATION MEMORY_URI [--actor=owner]
  mcma verify LOCATION
  mcma list LOCATION [--actor=owner]
  mcma tree LOCATION [--actor=owner]

  mcma access-init LOCATION [--actor=owner] [--policy=FILE.json]
  mcma access-check LOCATION ACTOR ACTION MEMORY_URI
  mcma permissions-show LOCATION [--actor=owner]
  mcma permissions-set LOCATION FILE.json [--actor=owner]
  mcma vault-put LOCATION NAME ENV_VAR [--type=secret] [--actor=owner]
  mcma vault-list LOCATION [--actor=owner]
  mcma vault-delete LOCATION NAME [--actor=owner]

  mcma knowledge-put LOCATION QUESTION_FILE ANSWER_FILE [options]
  mcma knowledge-check LOCATION QUESTION_FILE [options]
  mcma knowledge-show LOCATION QUESTION_FILE [--actor=owner]
  mcma knowledge-validate LOCATION QUESTION_FILE STATE CONFIDENCE REASON_FILE [options]
  mcma semantic-index LOCATION [--actor=librarian] [--dimensions=256]
  mcma semantic-check LOCATION QUESTION_FILE [options]
  mcma semantic-topk LOCATION QUESTION_FILE [options]
  mcma ask LOCATION QUESTION_FILE [options]

  mcma key-export LOCATION OUTPUT [--passphrase-env=ENV_NAME]
  mcma key-import RECOVERY_FILE [--passphrase-env=ENV_NAME] [--replace=yes]
  mcma migrate LOCATION HISTORICAL.mcma MEMORY_URI [options]
  mcma storage-copy SOURCE_LOCATION DESTINATION_LOCATION

No command prints raw vault secrets. vault-put reads the secret from ENV_VAR.

Provider credentials:
  GitHub: MCMA_GITHUB_TOKEN
  S3: MCMA_S3_* or standard AWS_* variables
  Google Cloud Storage: MCMA_GCS_ACCESS_TOKEN
  Google Drive: MCMA_GDRIVE_ACCESS_TOKEN
  Azure Blob: MCMA_AZURE_SAS_TOKEN or MCMA_AZURE_BEARER_TOKEN
  Alibaba OSS: MCMA_OSS_* or ALIBABA_CLOUD_* variables
  WebDAV: MCMA_WEBDAV_AUTH plus username/password or bearer token

write/update options:
  --format=text|markdown|xml|json|binary
  --temperature=hot|warm|cold|frozen
  --layer=40-semantic
  --scope=user
  --maturity=raw|observed|classified|knowledge|confirmed

knowledge / semantic options:
  --actor=owner|ai|librarian
  --answer-format=text|markdown|json
  --confidence=0.0..1.0
  --validation=unverified|plausible|supported|verified|disputed|retracted
  --freshness=immutable|stable|dynamic|volatile
  --max-age=SECONDS
  --reuse=always|reuse-unless-stale|revalidate-if-stale|never-direct
  --provenance=FILE.json
  --current=yes|no
  --min-confidence=0.0..1.0
  --min-similarity=-1.0..1.0
  --top-k=1..100
  --dimensions=256|512|1024
  --embedding-provider=bedrock-titan-v2|ollama|llamacpp

ask options:
  --actor=ai
  --embedding-provider=none|bedrock-titan-v2|ollama|llamacpp
  --generation-provider=none|bedrock-converse|ollama|llamacpp
  --remember=yes|no
  --capture-confidence=0.0..1.0
  --capture-validation=unverified|plausible|supported|verified|disputed|retracted
  --capture-freshness=immutable|stable|dynamic|volatile
  --capture-max-age=SECONDS
  --capture-reuse=always|reuse-unless-stale|revalidate-if-stale|never-direct
  --capture-provenance=FILE.json

Bedrock credentials are read from AWS_BEARER_TOKEN_BEDROCK or standard AWS credentials.
Bedrock generation also requires MCMA_BEDROCK_CHAT_MODEL.
Ollama defaults to http://127.0.0.1:11434 and requires MCMA_OLLAMA_EMBED_MODEL and/or MCMA_OLLAMA_CHAT_MODEL.
llama.cpp defaults to separate local servers on 127.0.0.1:8081 (embeddings) and 127.0.0.1:8080 (chat).
INPUT may be '-' to read from STDIN.
TXT
        . PHP_EOL;
    }

    private function parseOptions(array $args): array
    {
        $out=[];
        foreach($args as $arg){
            if(!str_starts_with($arg,'--')||!str_contains($arg,'=')) $this->fail('Invalid option: '.$arg,2);
            [$name,$value]=explode('=',substr($arg,2),2);
            $out[$name]=$value;
        }
        return $out;
    }

    private function pretty(mixed $value): string
    {
        return json_encode($value,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    }

    private function storage(string $location): StorageAdapter
    {
        return StorageFactory::fromLocation($location);
    }

    private function library(string $location): Library
    {
        return Library::open($this->storage($location));
    }

    private function actor(array $opts): string
    {
        return $opts['actor'] ?? 'owner';
    }

    private function readInput(string $input,string $format): mixed
    {
        $bytes=$input==='-'?stream_get_contents(STDIN):@file_get_contents($input);
        if($bytes===false) $this->fail('Unable to read input: '.$input);
        if($format==='json'){
            try{return json_decode($bytes,true,512,JSON_THROW_ON_ERROR);}
            catch(Throwable $e){$this->fail('Input is not valid JSON: '.$e->getMessage());}
        }
        return $bytes;
    }

    private function readJsonValue(string $path): mixed
    {
        $bytes=@file_get_contents($path);
        if($bytes===false) $this->fail('Unable to read JSON file: '.$path);
        try{return json_decode($bytes,true,512,JSON_THROW_ON_ERROR);}
        catch(Throwable $e){$this->fail('Invalid JSON file: '.$e->getMessage());}
    }

    private function readJsonObject(string $path): array
    {
        $value=$this->readJsonValue($path);
        if(!is_array($value)||array_is_list($value)) $this->fail('JSON file must contain an object');
        return $value;
    }

    private function readJsonArray(string $path): array
    {
        $value=$this->readJsonValue($path);
        if(!is_array($value)||!array_is_list($value)) $this->fail('JSON file must contain an array');
        return $value;
    }

    private function readTextFile(string $path): string
    {
        $bytes=@file_get_contents($path);
        if($bytes===false) $this->fail('Unable to read text file: '.$path);
        $value=trim($bytes);
        if($value==='') $this->fail('Text file must not be empty: '.$path);
        return $value;
    }

    private function yesNo(array $opts,string $name,bool $default=false): bool
    {
        if(!array_key_exists($name,$opts)) return $default;
        $value=strtolower(trim((string)$opts[$name]));
        if(in_array($value,['yes','true','1','on'],true)) return true;
        if(in_array($value,['no','false','0','off'],true)) return false;
        $this->fail('Invalid boolean option --'.$name.'='.$opts[$name]);
    }

    private function envSecret(string $name): string
    {
        if(!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/',$name)) $this->fail('Invalid environment variable name');
        $value=getenv($name);
        if(!is_string($value)) $this->fail('Secret environment variable is not set: '.$name);
        return $value;
    }

    private function embeddingProvider(array $opts): EmbeddingProvider
    {
        $provider=$this->providers->embedding($opts,false);
        if($provider===null) $this->fail('Embedding provider is required');
        return $provider;
    }

    private function optionalEmbeddingProvider(array $opts): ?EmbeddingProvider
    {
        return $this->providers->embedding($opts,true);
    }

    private function generationProvider(array $opts): ?GenerationProvider
    {
        return $this->providers->generation($opts);
    }

    private function recoveryPassphrase(array $opts): string
    {
        $name=$opts['passphrase-env']??'MCMA_RECOVERY_PASSPHRASE';
        return $this->envSecret($name);
    }

    private function renderTree(array $tree,string $prefix=''): string
    {
        $lines=[];$keys=array_values(array_filter(array_keys($tree),static fn(string $k):bool=>$k!=='@object_id'));sort($keys,SORT_STRING);
        foreach($keys as $i=>$key){
            $last=$i===count($keys)-1;$lines[]=$prefix.($last?'└── ':'├── ').$key;$child=$tree[$key];
            if(is_array($child)){
                $cp=$prefix.($last?'    ':'│   ');
                if(isset($child['@object_id']))$lines[]=$cp.'└── '.$child['@object_id'];
                $nested=$this->renderTree($child,$cp);if($nested!=='')$lines[]=$nested;
            }
        }
        return implode(PHP_EOL,$lines);
    }
}
