const NAME_MAP = {
    'ui.ChatView.send.normal': 'ChatView.vue::send (normal)',
    'ui.ChatView.send.dbOnly': 'ChatView.vue::useDbStepsOnly',

    'support_chat.ask': 'SupportChatService::ask',

    'usage.increment': 'UsageTracker::increment',

    'kb.match': 'SupportChatService::findMatches',
    'kb.build_context': 'SupportChatService::buildKbContext',

    'cache.history_load': 'SupportChatService::loadHistory',
    'cache.history_save': 'SupportChatService::saveHistory',

    'history.ensure_system_prompt': 'SupportChatService::ensureSystemPrompt',
    'history.trim': 'SupportChatService::trimHistory',

    'ai.call': 'AiChatGateway::chat',

    'gateway.ai_chat.chat': 'AiChatGateway::chat (internal)',
    'registry.adapter.resolve': 'ChatAdapterRegistry::resolve',
    'registry.adapter.created': 'ChatAdapterRegistry::created',
    'registry.factory.supports': 'ProviderFactory::supports',
    'registry.factory.create_adapter': 'ProviderFactory::create',

    'adapter.handle_request': 'Adapter::handleRequest (generic)',
    'adapter.gemini.handleRequest': 'GeminiChatAdapter::handleRequest',
    'adapter.gemini.vendor_call': 'GoogleGeminiChatAdapter::handleRequest',
    'adapter.openai.handleRequest': 'OpenAiChatAdapter::handleRequest',
    'adapter.openai.vendor_call': 'OpenAI SDK call',

    'http.client.request': 'TracingHttpClient::request',

    'http.api.chat': 'axios -> POST /api/chat',
    'controller.ChatController::chat': 'ChatController::chat [POST /api/chat | app_chat]',

}

export function mapLabel(spanName) {
    return NAME_MAP[spanName] ?? spanName
}
