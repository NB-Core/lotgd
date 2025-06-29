<?php
namespace Lotgd\Installer;

class Installer
{
    public function runStage(int $stage): void
    {
        $method = 'stage' . $stage;
        if (method_exists($this, $method)) {
            $this->$method();
        } else {
            $this->stageDefault();
        }
    }

    protected function includeStage($file): void
    {
        global $session, $logd_version, $recommended_modules, $noinstallnavs, $stage, $DB_USEDATACACHE;
        require __DIR__ . "/installer_stage_{$file}.php";
    }

    public function stage0(): void { $this->includeStage(0); }
    public function stage1(): void { $this->includeStage(1); }
    public function stage2(): void { $this->includeStage(2); }
    public function stage3(): void { $this->includeStage(3); }
    public function stage4(): void { $this->includeStage(4); }
    public function stage5(): void { $this->includeStage(5); }
    public function stage6(): void { $this->includeStage(6); }
    public function stage7(): void { $this->includeStage(7); }
    public function stage8(): void { $this->includeStage(8); }
    public function stage9(): void { $this->includeStage(9); }
    public function stage10(): void { $this->includeStage(10); }
    public function stageDefault(): void { $this->includeStage('default'); }
}
