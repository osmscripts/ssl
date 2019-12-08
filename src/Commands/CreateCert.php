<?php

namespace Osmscripts\Ssl\Commands;

use OsmScripts\Core\Command;
use OsmScripts\Core\Files;
use OsmScripts\Core\Script;
use OsmScripts\Core\Shell;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

/** @noinspection PhpUnused */

/**
 * `create:cert` shell command class.
 *
 * @property string $project
 * @property string $domain
 * @property Shell $shell @required Helper for running commands in local shell
 * @property Files $files @required Helper for generating files.
 * @property string $path
 * @property string $filename
 * @property string $site_filename
 */
class CreateCert extends Command
{
    public $ssl_cert_begin = "    # SSL Certificate BEGIN\n";
    public $ssl_cert_end = "    # SSL Certificate END\n";
    public $redirect_to_https_begin = "# Redirect to HTTPS BEGIN\n";
    public $redirect_to_https_end = "# Redirect to HTTPS END\n";

    #region Properties
    public function default($property) {
        /* @var Script $script */
        global $script;

        switch ($property) {
            case 'project': return $this->input->getArgument('project');
            case 'domain': return $this->input->getOption('domain')
                ?: $this->project;
            case 'shell': return $script->singleton(Shell::class);
            case 'files': return $script->singleton(Files::class);
            case 'path': return "/etc/nginx/ssl";
            case 'filename': return "{$this->domain}.txt";
            case 'site_filename': return "/etc/nginx/sites-available/{$this->project}";
        }

        return parent::default($property);
    }
    #endregion

    protected function configure() {
        $this
            ->setDescription("Creates new SSL certificate for specified project and adds it to the project's Nginx configuration")
            ->addArgument('project', InputArgument::REQUIRED,
                "Name of project directory")
            ->addOption('domain', null, InputOption::VALUE_OPTIONAL,
                "Project's Web domain. If omitted, project directory name is used");
    }


    protected function handle() {
        if (!is_file($this->site_filename)) {
            throw new \Exception("Create project's Nginx configuration file '{$this->site_filename}' before running this command");
        }

        $this->shell->cd($this->path, function() {
            $this->files->save($this->filename,
                $this->files->render('cert_config',
                ['domain' => $this->domain]));

            $this->shell->run("openssl genrsa -out {$this->domain}.key 2048");
            $this->shell->run("openssl req -new -key {$this->domain}.key -out {$this->domain}.csr");
            $this->shell->run("openssl x509 -req -in {$this->domain}.csr -CA ca.crt -CAkey ca.key -CAcreateserial -out {$this->domain}.crt -days 1825 -sha256 -extfile {$this->domain}.txt");
            $this->shell->run("chmod 600 *");
        });

        $contents = file_get_contents($this->site_filename);
        $contents = $this->includeSslCert($contents);
        $contents = $this->redirectToHttps($contents);

        $this->files->save($this->site_filename, $contents);
        $this->shell->run("service nginx restart");
    }

    protected function includeSslCert($contents) {
        if (($pos = mb_strpos($contents, $this->ssl_cert_begin)) !== false) {
            $length = mb_strpos($contents, $this->ssl_cert_end)
                + mb_strlen($this->ssl_cert_end)
                - $pos;
        }
        else {
            $pos = mb_strrpos($contents, '}');
            $length = 0;
        }

        return mb_substr($contents, 0, $pos) .
            $this->ssl_cert_begin .
            $this->files->render('include_ssl_cert',
                ['domain' => $this->domain]) .
            $this->ssl_cert_end .
            mb_substr($contents, $pos + $length);
    }

    protected function redirectToHttps(string $contents) {
        $contents = preg_replace('/\s*listen\s+(?:\[::]:)?80;/u',
            '', $contents);

        if (($pos = mb_strpos($contents, $this->redirect_to_https_begin)) !== false) {
            $length = mb_strpos($contents, $this->redirect_to_https_end)
                + mb_strlen($this->redirect_to_https_end)
                - $pos;
        }
        else {
            $pos = mb_strlen($contents);
            $length = 0;
        }

        return mb_substr($contents, 0, $pos) .
            $this->redirect_to_https_begin .
            $this->files->render('redirect_to_https',
                ['domain' => $this->domain]) .
            $this->redirect_to_https_end .
            mb_substr($contents, $pos + $length);
    }
}