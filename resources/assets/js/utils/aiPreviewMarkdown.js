import MarkdownIt from 'markdown-it';

// AI文本不允许执行HTML，也不加载模型插入的图片。
const markdown = new MarkdownIt({html: false, linkify: true, breaks: true});
markdown.disable('image');
const defaultLink = markdown.renderer.rules.link_open || ((tokens, idx, options, env, self) => self.renderToken(tokens, idx, options));
markdown.renderer.rules.link_open = (tokens, idx, options, env, self) => {
    tokens[idx].attrSet('target', '_blank');
    tokens[idx].attrSet('rel', 'noopener noreferrer');
    return defaultLink(tokens, idx, options, env, self);
};

export function renderAiPreview(text) {
    return markdown.render(String(text || ''));
}
