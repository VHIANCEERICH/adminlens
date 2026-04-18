<?php

define('AI_PROVIDER', getenv('AI_PROVIDER') ?: 'ollama');
define('OPENAI_API_KEY', getenv('OPENAI_API_KEY') ?: 'AIzaSyAQkS82DRHSpXE65NItpky5MHsghq86Vmc');
define('OPENAI_MODEL', getenv('OPENAI_MODEL') ?: 'gpt-4o-mini');
define('OLLAMA_URL', getenv('OLLAMA_URL') ?: 'http://localhost:11434');
define('OLLAMA_MODEL', getenv('OLLAMA_MODEL') ?: 'llama3');
define('CHARTS_DIR', __DIR__ . '/../assets/charts/');
define('PYTHON_CMD', getenv('PYTHON_CMD') ?: 'C:\\Users\\User\\AppData\\Local\\Programs\\Python\\Python313\\python.exe');

