import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import type { ReactNode } from 'react';
import { ApiKeyTable } from '@/components/pg/api-key-table';
import type { ApiKey } from '@/components/pg/api-key-table';
import { PgButton } from '@/components/pg/button';
import { CredentialsDialog } from '@/components/pg/credentials-dialog';
import type { Credentials } from '@/components/pg/credentials-dialog';
import { Panel } from '@/components/pg/data-table';
import { EmptyState } from '@/components/pg/empty-state';
import { Field, TextArea, TextInput } from '@/components/pg/field';
import { PageHeader } from '@/components/pg/page-header';
import { SecureActionDialog } from '@/components/pg/secure-action-dialog';
import developers from '@/routes/partner/developers';
import apiKeys from '@/routes/partner/developers/api-keys';

type Endpoints = {
    return_url: string | null;
    callback_url: string | null;
    payin_webhook_url: string | null;
    payout_webhook_url: string | null;
};

type Props = {
    partner: {
        name: string;
        code: string;
        api_version: string;
        status: string;
    };
    keys: ApiKey[] | null;
    endpoints: Endpoints | null;
    ips: string[] | null;
    can: {
        issue_keys: boolean;
        revoke_keys: boolean;
        update_endpoints: boolean;
        update_ips: boolean;
    };
    overlap_hours: number;
};

const ENDPOINT_FIELDS: [keyof Endpoints, string, string][] = [
    ['return_url', 'Return URL', 'Where your customer lands after paying.'],
    ['callback_url', 'Pay-in callback URL', ''],
    [
        'payin_webhook_url',
        'Pay-in webhook URL',
        'We POST signed pay-in status updates here.',
    ],
    [
        'payout_webhook_url',
        'Payout webhook URL',
        'We POST signed payout status updates here.',
    ],
];

export default function Developers({
    partner,
    keys,
    endpoints,
    ips,
    can,
    overlap_hours,
}: Props) {
    const flashCredentials =
        (usePage().flash as { credentials?: Credentials }).credentials ?? null;
    const [credentials, setCredentials] = useState<Credentials | null>(
        flashCredentials,
    );
    const [keyAction, setKeyAction] = useState<
        null | { type: 'issue' } | { type: 'revoke'; key: ApiKey }
    >(null);
    const hasActiveKey = (keys ?? []).some((key) => key.status === 'active');

    // Keep the secret on screen until closed: later requests (e.g. loading the
    // drawer) carry no flash data and must not clear it.
    useEffect(() => {
        if (flashCredentials) setCredentials(flashCredentials);
    }, [flashCredentials]);

    const endpointForm = useForm({
        return_url: endpoints?.return_url ?? '',
        callback_url: endpoints?.callback_url ?? '',
        payin_webhook_url: endpoints?.payin_webhook_url ?? '',
        payout_webhook_url: endpoints?.payout_webhook_url ?? '',
    });
    const ipForm = useForm({ ip_addresses: (ips ?? []).join('\n') });

    return (
        <>
            <Head title="API & Webhooks" />

            <PageHeader
                title="API & Webhooks"
                description={`Credentials, webhook endpoints and allowed IPs for ${partner.name}. API version ${partner.api_version}.`}
            />

            {keys !== null && (
                <Card
                    title="API credentials"
                    description={`Sign every API request with your secret. Rotating creates a new secret; the old one keeps working for ${overlap_hours} hours so you can switch without downtime.`}
                    action={
                        can.issue_keys && (
                            <PgButton
                                variant={hasActiveKey ? 'secondary' : 'primary'}
                                onClick={() => setKeyAction({ type: 'issue' })}
                            >
                                {hasActiveKey
                                    ? 'Rotate secret'
                                    : 'Generate key'}
                            </PgButton>
                        )
                    }
                >
                    <ApiKeyTable
                        keys={keys}
                        onRevoke={
                            can.revoke_keys
                                ? (key) => setKeyAction({ type: 'revoke', key })
                                : undefined
                        }
                    />
                    <p className="text-xs text-tx3">
                        The secret is shown only once, when it is generated.
                        PayGate staff can’t see it either; if it is lost, rotate
                        it.
                    </p>
                </Card>
            )}

            <div className="grid gap-4 lg:grid-cols-2">
                {endpoints !== null && (
                    <Card
                        title="Endpoints"
                        description="Where PayGate sends your customers and status updates."
                    >
                        <form
                            className="flex flex-col gap-3"
                            onSubmit={(event) => {
                                event.preventDefault();
                                endpointForm.put(developers.endpoints().url, {
                                    preserveScroll: true,
                                });
                            }}
                        >
                            {ENDPOINT_FIELDS.map(([key, label, hint]) => (
                                <Field
                                    key={key}
                                    label={label}
                                    hint={hint || undefined}
                                    error={endpointForm.errors[key]}
                                >
                                    <TextInput
                                        type="url"
                                        placeholder="https://"
                                        disabled={!can.update_endpoints}
                                        className="font-mono text-[12.5px]"
                                        value={endpointForm.data[key]}
                                        invalid={!!endpointForm.errors[key]}
                                        onChange={(event) =>
                                            endpointForm.setData(
                                                key,
                                                event.target.value,
                                            )
                                        }
                                    />
                                </Field>
                            ))}
                            {can.update_endpoints && (
                                <div className="flex justify-end">
                                    <PgButton
                                        type="submit"
                                        variant="primary"
                                        disabled={
                                            endpointForm.processing ||
                                            !endpointForm.isDirty
                                        }
                                    >
                                        Save endpoints
                                    </PgButton>
                                </div>
                            )}
                        </form>
                    </Card>
                )}

                {ips !== null && (
                    <Card
                        title="Allowed IPs"
                        description="Your servers’ addresses. API requests from other addresses will be refused once enforcement is switched on."
                    >
                        <form
                            className="flex flex-col gap-3"
                            onSubmit={(event) => {
                                event.preventDefault();
                                ipForm.put(developers.ipRules().url, {
                                    preserveScroll: true,
                                });
                            }}
                        >
                            <Field
                                label="Addresses"
                                hint="One per line or comma separated; ranges like 10.0.0.0/24 allowed."
                                error={ipForm.errors.ip_addresses}
                            >
                                <TextArea
                                    rows={6}
                                    disabled={!can.update_ips}
                                    className="font-mono text-[13px]"
                                    placeholder="52.66.45.184"
                                    value={ipForm.data.ip_addresses}
                                    invalid={!!ipForm.errors.ip_addresses}
                                    onChange={(event) =>
                                        ipForm.setData(
                                            'ip_addresses',
                                            event.target.value,
                                        )
                                    }
                                />
                            </Field>
                            {can.update_ips && (
                                <div className="flex justify-end">
                                    <PgButton
                                        type="submit"
                                        variant="primary"
                                        disabled={
                                            ipForm.processing || !ipForm.isDirty
                                        }
                                    >
                                        Save IPs
                                    </PgButton>
                                </div>
                            )}
                        </form>
                    </Card>
                )}
            </div>

            <Card
                title="Webhook logs"
                description="Every webhook we send you, with its response."
            >
                <EmptyState
                    title="Webhook deliveries are shown per pay-in"
                    description="Open a pay-in under Payments › Pay-in and choose its Webhooks tab to see every attempt and resend it."
                />
            </Card>

            <SecureActionDialog
                open={keyAction?.type === 'issue'}
                onClose={() => setKeyAction(null)}
                title={
                    hasActiveKey
                        ? 'Rotate your API secret?'
                        : 'Generate an API key?'
                }
                description={
                    hasActiveKey
                        ? `A new secret is created and shown once. The current one keeps working for ${overlap_hours} hours, then stops.`
                        : 'The secret is shown once after you confirm.'
                }
                submitLabel={hasActiveKey ? 'Rotate secret' : 'Generate key'}
                method="post"
                url={apiKeys.store().url}
                onSuccess={() => router.reload({ only: ['keys'] })}
            />

            {keyAction?.type === 'revoke' && (
                <SecureActionDialog
                    open
                    onClose={() => setKeyAction(null)}
                    title={`Revoke ${keyAction.key.key_id}?`}
                    description="Requests signed with this key are refused immediately. Generate a new key to continue using the API."
                    submitLabel="Revoke key"
                    method="delete"
                    askReason
                    url={apiKeys.destroy(keyAction.key.id).url}
                    onSuccess={() => router.reload({ only: ['keys'] })}
                />
            )}

            <CredentialsDialog
                credentials={credentials}
                onClose={() => setCredentials(null)}
            />
        </>
    );
}

function Card({
    title,
    description,
    action,
    children,
}: {
    title: string;
    description?: string;
    action?: ReactNode;
    children: ReactNode;
}) {
    return (
        <Panel className="flex flex-col gap-4 p-4">
            <div className="flex items-start justify-between gap-3">
                <div>
                    <div className="text-sm font-semibold">{title}</div>
                    {description && (
                        <div className="mt-0.5 text-xs text-tx3">
                            {description}
                        </div>
                    )}
                </div>
                {action}
            </div>
            {children}
        </Panel>
    );
}
