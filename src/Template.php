<?php

namespace Akyltist\RunzyTemplate;

class RunzyTemplate {
    public function render(string $name): string {
        return "Hello, {$name}! RunzyTemplate is working.";
    }
}