import type { ReactNode } from 'react';

/** Small bordered table for drawers and cards. */
export function SimpleTable({
    headers,
    rows,
    empty,
}: {
    headers: string[];
    rows: ReactNode[][];
    empty?: string;
}) {
    if (rows.length === 0) {
        return <p className="text-xs text-tx3">{empty}</p>;
    }

    return (
        <div className="overflow-x-auto rounded-lg border border-ln">
            <table className="w-full text-[13px]">
                <thead>
                    <tr className="bg-sf2 text-xs text-tx3">
                        {headers.map((header) => (
                            <th
                                key={header}
                                className="px-3 py-2 text-left font-medium"
                            >
                                {header}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody>
                    {rows.map((row, index) => (
                        <tr key={index} className="border-t border-ln2">
                            {row.map((cell, cellIndex) => (
                                <td
                                    key={cellIndex}
                                    className="px-3 py-2 align-middle"
                                >
                                    {cell}
                                </td>
                            ))}
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
