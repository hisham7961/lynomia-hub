<?php

namespace App\Console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * بديلٌ يحمل اسمَ أمرٍ هادمٍ فيحلّ محلَّه — **فلا تُحمَّل شيفرتُه ولا تُنفَّذ
 * خطوةٌ منها**. يقبل أي خيارٍ يُمرَّر إليه (`ignoreValidationErrors`) كي لا
 * يتحوّل المنعُ إلى خطأ «خيارٌ غير موجود» يُربك قارئه بدل أن يُفهمه.
 */
class BlockedCommand extends Command
{
    public function __construct(string $name, protected string $why)
    {
        parent::__construct($name);
        $this->setDescription('محجوب: يمحو بيانات — انظر HUB_ALLOW_DESTRUCTIVE');
        $this->ignoreValidationErrors();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<error>' . $this->why . '</error>');

        return self::FAILURE;
    }
}
