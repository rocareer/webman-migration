<?php

namespace Rocareer\WebmanMigration\command;

use Phinx\Console\PhinxApplication;
use plugin\radmin\support\think\orm\Rdb;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

class MigrateRun extends Command
{
    protected static $defaultName = 'migrate:run';
    protected static $defaultDescription = 'Run Phinx migrations';

    /**
     * @return void
     */
    protected function configure()
    {
        $this->addArgument('name', InputArgument::OPTIONAL, 'Migration name')
            ->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'Path to the Phinx configuration file', 'phinx.php')
            ->addOption('target', 't', InputOption::VALUE_REQUIRED, 'Target version for the migration');
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {



        // 设置数据库连接

        $phinx = new PhinxApplication();
        $phinx->setAutoExit(false);

        // 构建 Phinx 命令参数
        $phinxInput = [
            'command' => 'migrate', // 默认执行 migrate 命令
        ];

        // 如果指定了迁移名称
        if ($name = $input->getArgument('name')) {
            $phinxInput['name'] = $name; // 获取迁移名称
        }

        // 如果指定了配置文件
        $phinxInput['--configuration'] = base_path() . '/config/plugin/rocareer/webman-migration/migrate.php'; // 修改为你的默认配置文件路径

        // 打印调试信息
        $output->writeln('Phinx Input: ' . json_encode($phinxInput, JSON_PRETTY_PRINT));

        // 如果指定了目标版本
        if ($target = $input->getOption('target')) {
            $phinxInput['--target'] = $target;
        }

        // 创建新的 ArrayInput 实例
        $phinxInput = new ArrayInput($phinxInput);
        $outputBuffer = new BufferedOutput();

        // 在执行迁移之前，设置表前缀
        $this->setTablePrefix();

        $phinx->run($phinxInput, $outputBuffer);

        $output->writeln($outputBuffer->fetch());



        return self::SUCCESS;
    }

    protected function setTablePrefix()
    {
        // 获取数据库配置
        $config = config('think-orm.connections.mysql');

        // 设置表前缀
        if (isset($config['prefix'])) {
            // 设置 Phinx 配置中的表前缀
            $phinxConfig = base_path() . '/config/plugin/rocareer/webman-migration/migrate.php';
            $phinxConfigContent = file_get_contents($phinxConfig);
            $phinxConfigContent = str_replace('\'prefix\' => \'\',', '\'prefix\' => \'' . $config['prefix'] . '\',', $phinxConfigContent);
            file_put_contents($phinxConfig, $phinxConfigContent);
        }
    }
}
