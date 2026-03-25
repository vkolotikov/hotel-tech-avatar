import ReactMarkdown from 'react-markdown';
import remarkGfm from 'remark-gfm';

type MessageMarkdownProps = {
  content: string;
};

export default function MessageMarkdown({ content }: MessageMarkdownProps) {
  return (
    <div className="chat-markdown">
      <ReactMarkdown
        remarkPlugins={[remarkGfm]}
        components={{
          a: ({ node: _node, ...props }) => (
            <a {...props} target="_blank" rel="noreferrer noopener" />
          ),
        }}
      >
        {content}
      </ReactMarkdown>
    </div>
  );
}
