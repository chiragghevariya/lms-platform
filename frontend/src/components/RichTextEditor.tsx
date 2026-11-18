import Link from '@tiptap/extension-link'
import Placeholder from '@tiptap/extension-placeholder'
import { EditorContent, useEditor } from '@tiptap/react'
import StarterKit from '@tiptap/starter-kit'
import {
  Bold,
  Code,
  Heading2,
  Heading3,
  Italic,
  Link2,
  List,
  ListOrdered,
  Quote,
  Redo2,
  Undo2,
} from 'lucide-react'
import { useEffect } from 'react'
import { cn } from '@/lib/utils'

interface Props {
  value: string | null
  onChange: (html: string) => void
  placeholder?: string
  id?: string
  'aria-labelledby'?: string
}

/** Tiptap editor limited to the formatting the API keeps (headings, lists, links, quotes, code). */
export function RichTextEditor({ value, onChange, placeholder, id, ...aria }: Props) {
  const editor = useEditor({
    extensions: [
      StarterKit.configure({ heading: { levels: [2, 3] }, link: false }),
      Link.configure({ openOnClick: false, autolink: true, protocols: ['http', 'https', 'mailto'] }),
      Placeholder.configure({ placeholder: placeholder ?? 'Write something…' }),
    ],
    content: value ?? '',
    editorProps: {
      attributes: {
        id: id ?? '',
        role: 'textbox',
        'aria-multiline': 'true',
        ...(aria['aria-labelledby'] ? { 'aria-labelledby': aria['aria-labelledby'] } : {}),
        class:
          'min-h-40 px-3 py-2 text-sm focus:outline-none [&_h2]:text-lg [&_h2]:font-semibold [&_h3]:font-semibold [&_ol]:list-decimal [&_ol]:pl-6 [&_ul]:list-disc [&_ul]:pl-6 [&_blockquote]:border-l-4 [&_blockquote]:pl-3 [&_a]:text-primary [&_a]:underline [&_p.is-editor-empty:first-child]:before:text-muted-foreground [&_p.is-editor-empty:first-child]:before:content-[attr(data-placeholder)] [&_p.is-editor-empty:first-child]:before:float-left [&_p.is-editor-empty:first-child]:before:h-0',
      },
    },
    onUpdate: ({ editor }) => onChange(editor.isEmpty ? '' : editor.getHTML()),
  })

  // Sync when the value is replaced from outside (e.g. switching lessons).
  useEffect(() => {
    if (editor && value !== null && value !== editor.getHTML() && !editor.isFocused) {
      editor.commands.setContent(value)
    }
  }, [editor, value])

  if (!editor) return null

  const btn = (label: string, active: boolean, run: () => void, icon: React.ReactNode) => (
    <button
      type="button"
      aria-label={label}
      aria-pressed={active}
      onClick={run}
      className={cn('hover:bg-muted rounded p-1.5', active && 'bg-secondary text-secondary-foreground')}
    >
      {icon}
    </button>
  )

  const setLink = () => {
    const prev = editor.getAttributes('link').href as string | undefined
    const url = window.prompt('Link URL', prev ?? 'https://')
    if (url === null) return
    if (url === '') editor.chain().focus().unsetLink().run()
    else editor.chain().focus().extendMarkRange('link').setLink({ href: url }).run()
  }

  return (
    <div className="border-input bg-background focus-within:ring-ring rounded-md border focus-within:ring-2">
      <div
        role="toolbar"
        aria-label="Formatting"
        className="flex flex-wrap gap-0.5 border-b p-1 [&_svg]:size-4"
      >
        {btn('Bold', editor.isActive('bold'), () => editor.chain().focus().toggleBold().run(), <Bold />)}
        {btn(
          'Italic',
          editor.isActive('italic'),
          () => editor.chain().focus().toggleItalic().run(),
          <Italic />,
        )}
        {btn(
          'Heading',
          editor.isActive('heading', { level: 2 }),
          () => editor.chain().focus().toggleHeading({ level: 2 }).run(),
          <Heading2 />,
        )}
        {btn(
          'Subheading',
          editor.isActive('heading', { level: 3 }),
          () => editor.chain().focus().toggleHeading({ level: 3 }).run(),
          <Heading3 />,
        )}
        {btn(
          'Bullet list',
          editor.isActive('bulletList'),
          () => editor.chain().focus().toggleBulletList().run(),
          <List />,
        )}
        {btn(
          'Numbered list',
          editor.isActive('orderedList'),
          () => editor.chain().focus().toggleOrderedList().run(),
          <ListOrdered />,
        )}
        {btn(
          'Quote',
          editor.isActive('blockquote'),
          () => editor.chain().focus().toggleBlockquote().run(),
          <Quote />,
        )}
        {btn(
          'Code',
          editor.isActive('codeBlock'),
          () => editor.chain().focus().toggleCodeBlock().run(),
          <Code />,
        )}
        {btn('Link', editor.isActive('link'), setLink, <Link2 />)}
        <span className="bg-border mx-1 w-px" aria-hidden />
        {btn('Undo', false, () => editor.chain().focus().undo().run(), <Undo2 />)}
        {btn('Redo', false, () => editor.chain().focus().redo().run(), <Redo2 />)}
      </div>
      <EditorContent editor={editor} />
    </div>
  )
}
