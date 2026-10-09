// Author: ramanpal singh | URL: https://kwebby.com
export type RecordData = {
    id: string;
    version?: number;
    created_at?: string;
    updated_at?: string;
    [key: string]: any;
};
export type User = {
    id: string;
    name: string;
    email: string;
    roles: string[];
    permissions?: string[];
};
export type WorkspaceProps = {
    user: User;
    section?: string;
    records?: RecordData[];
    stats?: Record<string, any>;
    settings?: Record<string, any>;
    notifications?: RecordData[];
    csrf_token?: string;
    [key: string]: any;
};
export type FieldSpec = {
    name: string;
    label: string;
    type?:
        | "text"
        | "email"
        | "textarea"
        | "select"
        | "date"
        | "number"
        | "month"
        | "password";
    options?: string[];
    required?: boolean;
    hint?: string;
    placeholder?: string;
};
