// Author: ramanpal singh | URL: https://kwebby.com
import {
    createReactBlockSpec,
    getDefaultReactSlashMenuItems,
    SuggestionMenuController,
    useCreateBlockNote,
} from "@blocknote/react";
import {
    BlockNoteSchema,
    defaultBlockSpecs,
    filterSuggestionItems,
    insertOrUpdateBlockForSlashMenu,
} from "@blocknote/core";
import { BlockNoteView } from "@blocknote/shadcn";
import {
    BookOpen,
    Braces,
    CircleHelp,
    SeparatorHorizontal,
} from "lucide-react";
import "@blocknote/shadcn/style.css";
const Citation = createReactBlockSpec(
    {
        type: "citation",
        propSchema: {
            source: { default: "" },
            url: { default: "" },
            pinpoint: { default: "" },
        },
        content: "inline",
    },
    {
        render: (props) => (
            <div className="legal-block citation-block">
                <div className="legal-block-label" contentEditable={false}>
                    <BookOpen size={14} />
                    Citation
                </div>
                <div ref={props.contentRef} />
                <div className="legal-block-fields" contentEditable={false}>
                    <input
                        aria-label="Citation source"
                        placeholder="Source / authority"
                        value={props.block.props.source}
                        onChange={(e) =>
                            props.editor.updateBlock(props.block, {
                                props: { source: e.target.value },
                            })
                        }
                    />
                    <input
                        aria-label="Citation URL"
                        placeholder="https://…"
                        value={props.block.props.url}
                        onChange={(e) =>
                            props.editor.updateBlock(props.block, {
                                props: { url: e.target.value },
                            })
                        }
                    />
                    <input
                        aria-label="Pinpoint citation"
                        placeholder="Page / paragraph"
                        value={props.block.props.pinpoint}
                        onChange={(e) =>
                            props.editor.updateBlock(props.block, {
                                props: { pinpoint: e.target.value },
                            })
                        }
                    />
                </div>
            </div>
        ),
    },
);
const MergeField = createReactBlockSpec(
    {
        type: "mergeField",
        propSchema: {
            field: { default: "client.name" },
            fallback: { default: "" },
        },
        content: "inline",
    },
    {
        render: (props) => (
            <div className="legal-block merge-block">
                <div className="legal-block-label" contentEditable={false}>
                    <Braces size={14} />
                    Merge field
                </div>
                <div ref={props.contentRef} />
                <div className="legal-block-fields" contentEditable={false}>
                    <input
                        aria-label="Merge field name"
                        placeholder="client.name"
                        value={props.block.props.field}
                        onChange={(e) =>
                            props.editor.updateBlock(props.block, {
                                props: { field: e.target.value },
                            })
                        }
                    />
                    <input
                        aria-label="Merge field fallback"
                        placeholder="Fallback text"
                        value={props.block.props.fallback}
                        onChange={(e) =>
                            props.editor.updateBlock(props.block, {
                                props: { fallback: e.target.value },
                            })
                        }
                    />
                </div>
            </div>
        ),
    },
);
const Question = createReactBlockSpec(
    {
        type: "question",
        propSchema: { resolved: { default: false } },
        content: "inline",
    },
    {
        render: (props) => (
            <div className="legal-block question-block">
                <div className="legal-block-label" contentEditable={false}>
                    <CircleHelp size={14} />
                    {props.block.props.resolved
                        ? "Resolved question"
                        : "Question for review"}
                    <label>
                        <input
                            type="checkbox"
                            checked={props.block.props.resolved}
                            onChange={(e) =>
                                props.editor.updateBlock(props.block, {
                                    props: { resolved: e.target.checked },
                                })
                            }
                        />
                        Resolved
                    </label>
                </div>
                <div ref={props.contentRef} />
            </div>
        ),
    },
);
const PageBreak = createReactBlockSpec(
    { type: "pageBreak", propSchema: {}, content: "none" },
    {
        render: () => (
            <div className="page-break-block" contentEditable={false}>
                <span />
                Page break
                <span />
            </div>
        ),
    },
);
const supported = Object.fromEntries(
    Object.entries(defaultBlockSpecs).filter(([name]) =>
        [
            "paragraph",
            "heading",
            "bulletListItem",
            "numberedListItem",
            "checkListItem",
            "quote",
            "table",
            "image",
            "file",
        ].includes(name),
    ),
) as any;
const schema = BlockNoteSchema.create({
    blockSpecs: {
        ...supported,
        citation: Citation(),
        mergeField: MergeField(),
        question: Question(),
        pageBreak: PageBreak(),
    },
});
export default function BlockEditor({
    blocks,
    onChange,
    editable = true,
}: {
    blocks: any[];
    onChange: (blocks: any[]) => void;
    editable?: boolean;
}) {
    const editor = useCreateBlockNote({
        schema,
        initialContent: blocks.length ? blocks : undefined,
    });
    return (
        <BlockNoteView
            editor={editor}
            editable={editable}
            theme="light"
            slashMenu={false}
            onChange={() => onChange(editor.document)}
        >
            <SuggestionMenuController
                triggerCharacter="/"
                getItems={async (query) =>
                    filterSuggestionItems(
                        [
                            ...getDefaultReactSlashMenuItems(editor),
                            ...[
                                {
                                    type: "citation",
                                    title: "Citation",
                                    icon: <BookOpen size={16} />,
                                    subtext:
                                        "Link the statement to its source.",
                                },
                                {
                                    type: "mergeField",
                                    title: "Merge field",
                                    icon: <Braces size={16} />,
                                    subtext: "Add a named template field.",
                                },
                                {
                                    type: "question",
                                    title: "Question for review",
                                    icon: <CircleHelp size={16} />,
                                    subtext: "Flag an unresolved fact.",
                                },
                                {
                                    type: "pageBreak",
                                    title: "Page break",
                                    icon: <SeparatorHorizontal size={16} />,
                                    subtext: "Start a new page in your export.",
                                },
                            ].map((item) => ({
                                title: item.title,
                                subtext: item.subtext,
                                group: "Legal writing",
                                icon: item.icon,
                                aliases: [item.type],
                                onItemClick: () =>
                                    insertOrUpdateBlockForSlashMenu(editor, {
                                        type: item.type as any,
                                    }),
                            })),
                        ],
                        query,
                    )
                }
            />
        </BlockNoteView>
    );
}
