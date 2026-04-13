<?php

namespace Chatbot;

interface AiClientInterface
{
    public function ask(array $historyContext);
}