import React, { useState, useEffect } from 'react';
import { motion, AnimatePresence } from 'framer-motion';
import {
    Building2,
    User,
    FileText,
    MapPin,
    Hash,
    Calendar,
    Clock,
    Plus,
    Trash2,
    Send,
    CheckCircle2,
    AlertCircle,
    Loader2,
    LayoutDashboard,
    ListFilter,
    ArrowRight,
    Eye,
    Download,
    ExternalLink,
    Search
} from 'lucide-react';
import axios from 'axios';

// Set default CSRF token for axios
const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
if (csrfToken) {
    axios.defaults.headers.common['X-CSRF-TOKEN'] = csrfToken;
}

const InputField = ({ label, icon: Icon, value, onChange, placeholder, type = "text" }) => (
    <div className="flex flex-col gap-1.5 w-full">
        <label className="text-sm font-semibold text-slate-600 ml-1">{label}</label>
        <div className="relative group">
            <div className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 group-focus-within:text-indigo-600 transition-colors">
                <Icon size={18} />
            </div>
            <input
                type={type}
                value={value}
                onChange={onChange}
                placeholder={placeholder}
                className="w-full pl-10 pr-4 py-3 bg-white border border-slate-200 rounded-2xl outline-none focus:ring-4 focus:ring-indigo-500/10 focus:border-indigo-500 transition-all text-slate-900 placeholder:text-slate-400 shadow-sm"
            />
        </div>
    </div>
);

const Section = ({ title, icon: Icon, children }) => (
    <motion.div
        initial={{ opacity: 0, y: 20 }}
        animate={{ opacity: 1, y: 0 }}
        className="p-8 bg-white/70 backdrop-blur-xl border border-white/40 rounded-3xl shadow-xl shadow-slate-200/50 space-y-6"
    >
        <div className="flex items-center gap-4 pb-4 border-b border-slate-100">
            <div className="p-2.5 bg-indigo-50 text-indigo-600 rounded-xl">
                <Icon size={22} />
            </div>
            <h2 className="text-xl font-bold text-slate-800 tracking-tight">{title}</h2>
        </div>
        <div className="grid grid-cols-1 md:grid-cols-2 gap-8">
            {children}
        </div>
    </motion.div>
);

const InvoiceTable = ({ invoices, onView }) => {
    const [search, setSearch] = useState('');

    const filtered = invoices.filter(inv =>
        inv.invoice_number.toLowerCase().includes(search.toLowerCase()) ||
        inv.seller_name.toLowerCase().includes(search.toLowerCase())
    );

    const getStatusBadge = (inv) => {
        const compliance = inv.compliance_results;
        const isReported = compliance?.reportingStatus === 'REPORTED';

        return (
            <span className={`inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-bold border ${isReported ? 'bg-indigo-50 text-indigo-700 border-indigo-100' : 'bg-green-50 text-green-700 border-green-100'}`}>
                <CheckCircle2 size={12} /> {isReported ? 'REPORTED' : 'COMPLIANT'}
            </span>
        );
    };

    return (
        <div className="space-y-6">
            <div className="flex items-center justify-between gap-4">
                <h2 className="text-2xl font-bold text-slate-900">Invoices History</h2>
                <div className="relative w-72">
                    <Search className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" size={18} />
                    <input
                        type="text"
                        placeholder="Search by invoice or seller..."
                        className="w-full pl-10 pr-4 py-2 bg-white border border-slate-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-indigo-500"
                        value={search}
                        onChange={e => setSearch(e.target.value)}
                    />
                </div>
            </div>

            <div className="overflow-hidden rounded-3xl border border-slate-200 shadow-sm bg-white">
                <table className="w-full text-left border-collapse">
                    <thead>
                        <tr className="bg-slate-50/50 text-slate-500 text-sm font-bold uppercase tracking-wider">
                            <th className="px-6 py-4">Invoice #</th>
                            <th className="px-6 py-4">Seller</th>
                            <th className="px-6 py-4">Date & Time</th>
                            <th className="px-6 py-4">Total (SAR)</th>
                            <th className="px-6 py-4">Status</th>
                            <th className="px-6 py-4 text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {filtered.map((inv) => (
                            <tr key={inv.id} className="hover:bg-slate-50/50 transition-colors">
                                <td className="px-6 py-4 font-bold text-slate-900">{inv.invoice_number}</td>
                                <td className="px-6 py-4 text-slate-600">{inv.seller_name}</td>
                                <td className="px-6 py-4">
                                    <div className="text-slate-900 text-sm font-medium">{inv.issue_date}</div>
                                    <div className="text-slate-400 text-xs">{inv.issue_time}</div>
                                </td>
                                <td className="px-6 py-4 font-bold text-indigo-600">{parseFloat(inv.total_amount).toLocaleString()}</td>
                                <td className="px-6 py-4">
                                    {getStatusBadge(inv)}
                                </td>
                                <td className="px-6 py-4 text-center">
                                    <button
                                        onClick={() => onView(inv)}
                                        className="p-2 text-slate-400 hover:text-indigo-600 hover:bg-indigo-50 rounded-lg transition-all"
                                    >
                                        <Eye size={20} />
                                    </button>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
                {filtered.length === 0 && (
                    <div className="p-12 text-center text-slate-400">
                        No invoices found. Create your first one!
                    </div>
                )}
            </div>
        </div>
    );
};

export default function App() {
    const [view, setView] = useState('create'); // 'create' or 'list'
    const [loading, setLoading] = useState(false);
    const [result, setResult] = useState(null);
    const [error, setError] = useState(null);
    const [invoices, setInvoices] = useState([]);
    const [selectedInvoice, setSelectedInvoice] = useState(null);

    const [form, setForm] = useState({
        seller: {
            name: 'Mh Software Solutions',
            vat_number: '301121971500003',
            crn: '301121971500003',
            custom_id: 'EGS1-' + Math.floor(Math.random() * 900000000 + 100000000),
            uuid: '6f4d20e0-6bfe-4a80-9389-7dabe6620f12',
            model: 'SmartDevice-V1',
        },
        location: {
            city: 'Riyadh',
            city_subdivision: 'Olaya',
            street: 'King Fahd Road',
            building: '2481',
            plot: '0000',
            postal: '12211',
        },
        invoice: {
            number: 'INV-' + new Date().getFullYear() + '-' + Math.floor(Math.random() * 9000 + 1000),
            date: new Date().toISOString().split('T')[0],
            time: new Date().toTimeString().split(' ')[0],
            previous_hash: 'NWZlY2ViNjZmZmM4NmYzOGQ5NTI3ODZjNmQ2OTZjNzljMmRiYzIzOWRkNGU5MWI0NjcyOWQ3M2EyN2ZiNTdlOQ==',
            counter: '1',
        },
        items: [
            { id: '1', name: 'mhsoftware Mobile Development ', quantity: 1, price: 100.00, vat_percent: 0.15 },
            { id: '2', name: 'mhsoftware Development ', quantity: 1, price: 200.00, vat_percent: 0.15 }
        ]
    });

    useEffect(() => {
        fetchInvoices();
    }, []);

    const fetchInvoices = async () => {
        try {
            const resp = await axios.get('/api/zatca/invoices');
            setInvoices(resp.data);
        } catch (err) {
            console.error("Failed to fetch invoices", err);
        }
    };

    const addItem = () => {
        setForm(prev => ({
            ...prev,
            items: [...prev.items, { id: Date.now().toString(), name: '', quantity: 1, price: 0, vat_percent: 0.15 }]
        }));
    };

    const removeItem = (id) => {
        setForm(prev => ({
            ...prev,
            items: prev.items.filter(item => item.id !== id)
        }));
    };

    const updateItem = (id, field, value) => {
        setForm(prev => ({
            ...prev,
            items: prev.items.map(item => item.id === id ? { ...item, [field]: value } : item)
        }));
    };

    const handleSubmit = async () => {
        setLoading(true);
        setError(null);
        setResult(null);
        try {
            const response = await axios.post('/api/zatca/phase-2', form);
            setResult(response.data);
            fetchInvoices();
        } catch (err) {
            setError(err.response?.data?.message || err.message);
        } finally {
            setLoading(false);
        }
    };

    const openDetails = (inv) => {
        setSelectedInvoice(inv);
    };

    return (
        <div className="min-h-screen bg-transparent py-14 px-4 sm:px-6 lg:px-8 font-sans transition-all duration-500">
            <div className="max-w-6xl mx-auto space-y-12">

                {/* Navigation Bar */}
                <div className="flex justify-center flex-wrap gap-4">
                    <nav className="inline-flex bg-white/50 backdrop-blur-lg p-1.5 rounded-[2rem] border border-white/50 shadow-lg shadow-slate-200/50">
                        <button
                            onClick={() => setView('create')}
                            className={`flex items-center gap-2 px-8 py-3 rounded-[1.75rem] font-bold transition-all duration-300 ${view === 'create' ? 'bg-indigo-600 text-white shadow-lg shadow-indigo-200' : 'text-slate-500 hover:text-slate-900 bg-transparent'}`}
                        >
                            <Plus size={20} /> Create Invoice
                        </button>
                        <button
                            onClick={() => setView('list')}
                            className={`flex items-center gap-2 px-8 py-3 rounded-[1.75rem] font-bold transition-all duration-300 ${view === 'list' ? 'bg-indigo-600 text-white shadow-lg shadow-indigo-200' : 'text-slate-500 hover:text-slate-900 bg-transparent'}`}
                        >
                            <LayoutDashboard size={20} /> Invoices History
                        </button>
                    </nav>
                </div>

                {/* Header */}
                <header className="text-center space-y-6">
                    <motion.div
                        initial={{ scale: 0.9, opacity: 0 }}
                        animate={{ scale: 1, opacity: 1 }}
                        className="inline-flex items-center gap-2 px-4 py-2 rounded-2xl bg-indigo-50 text-indigo-600 text-sm font-extrabold border border-indigo-100/50 uppercase tracking-widest"
                    >
                        <CheckCircle2 size={16} />
                        ZATCA Phase II Protocol
                    </motion.div>
                    <h1 className="text-6xl font-[900] text-slate-900 tracking-tight leading-tight">
                        Compliant <span className="bg-gradient-to-r from-indigo-600 to-blue-500 bg-clip-text text-transparent">Invoicing</span> Core
                    </h1>
                    <p className="text-slate-500 text-xl max-w-2xl mx-auto font-medium leading-relaxed">
                        The ultimate solution for Saudi Arabian simplified tax invoices.
                        Fully optimized for Phase 2 cryptographic requirements.
                    </p>
                </header>

                <AnimatePresence mode="wait">
                    {view === 'create' ? (
                        <motion.div
                            key="create-view"
                            initial={{ opacity: 0, x: -20 }}
                            animate={{ opacity: 1, x: 0 }}
                            exit={{ opacity: 0, x: 20 }}
                            className="grid grid-cols-1 gap-10"
                        >
                            {/* Form Sections */}
                            <div className="grid grid-cols-1 lg:grid-cols-2 gap-10">
                                <Section title="Seller Entity" icon={Building2}>
                                    <InputField
                                        label="Organization Name"
                                        icon={User}
                                        value={form.seller.name}
                                        onChange={e => setForm({ ...form, seller: { ...form.seller, name: e.target.value } })}
                                    />
                                    <InputField
                                        label="VAT Reg Number"
                                        icon={Hash}
                                        value={form.seller.vat_number}
                                        onChange={e => setForm({ ...form, seller: { ...form.seller, vat_number: e.target.value } })}
                                    />
                                    <InputField
                                        label="CR Number"
                                        icon={FileText}
                                        value={form.seller.crn}
                                        onChange={e => setForm({ ...form, seller: { ...form.seller, crn: e.target.value } })}
                                    />
                                    <div className="relative">
                                        <InputField
                                            label="EGS Model"
                                            icon={Hash}
                                            value={form.seller.model}
                                            onChange={e => setForm({ ...form, seller: { ...form.seller, model: e.target.value } })}
                                        />
                                        <p className="text-[10px] text-slate-400 mt-1 ml-1 uppercase font-bold tracking-tighter">
                                            (Electronic Generation Solution device or software version)
                                        </p>
                                    </div>
                                </Section>

                                <Section title="Address & Branch" icon={MapPin}>
                                    <InputField label="City" icon={MapPin} value={form.location.city} onChange={e => setForm({ ...form, location: { ...form.location, city: e.target.value } })} />
                                    <InputField label="Street" icon={MapPin} value={form.location.street} onChange={e => setForm({ ...form, location: { ...form.location, street: e.target.value } })} />
                                    <div className="grid grid-cols-2 gap-6">
                                        <InputField label="Building" icon={Hash} value={form.location.building} onChange={e => setForm({ ...form, location: { ...form.location, building: e.target.value } })} />
                                        <InputField label="Postal" icon={Hash} value={form.location.postal} onChange={e => setForm({ ...form, location: { ...form.location, postal: e.target.value } })} />
                                    </div>
                                    <InputField label="City Subdivision" icon={MapPin} value={form.location.city_subdivision} onChange={e => setForm({ ...form, location: { ...form.location, city_subdivision: e.target.value } })} />
                                </Section>
                            </div>

                            <Section title="Invoice Reference" icon={FileText}>
                                <InputField label="Unique Serial Number" icon={Hash} value={form.invoice.number} onChange={e => setForm({ ...form, invoice: { ...form.invoice, number: e.target.value } })} />
                                <div className="grid grid-cols-2 gap-6">
                                    <InputField label="Date of Issue" type="date" icon={Calendar} value={form.invoice.date} onChange={e => setForm({ ...form, invoice: { ...form.invoice, date: e.target.value } })} />
                                    <InputField label="Time of Issue" type="time" icon={Clock} value={form.invoice.time} onChange={e => setForm({ ...form, invoice: { ...form.invoice, time: e.target.value } })} />
                                </div>
                            </Section>

                            {/* Line Items Container */}
                            <motion.div
                                className="p-8 bg-white/70 backdrop-blur-xl border border-white/40 rounded-3xl shadow-xl space-y-8"
                                initial={{ opacity: 0 }}
                                animate={{ opacity: 1 }}
                            >
                                <div className="flex items-center justify-between pb-6 border-b border-slate-100">
                                    <h2 className="text-2xl font-black text-slate-800 flex items-center gap-4">
                                        <div className="p-3 bg-indigo-50 text-indigo-600 rounded-2xl">
                                            <ListFilter size={24} />
                                        </div>
                                        Items Breakdown
                                    </h2>
                                    <button
                                        onClick={addItem}
                                        className="px-6 py-3 bg-indigo-600 text-white rounded-2xl hover:bg-indigo-700 transition-all flex items-center gap-2 font-bold shadow-lg shadow-indigo-100"
                                    >
                                        <Plus size={20} /> Add Item
                                    </button>
                                </div>

                                <div className="space-y-6">
                                    {form.items.map((item, index) => (
                                        <motion.div
                                            key={item.id}
                                            layout
                                            initial={{ opacity: 0, scale: 0.98 }}
                                            animate={{ opacity: 1, scale: 1 }}
                                            className="p-6 bg-slate-50/50 rounded-2xl border border-slate-200/50 grid grid-cols-1 md:grid-cols-12 gap-6 items-end"
                                        >
                                            <div className="md:col-span-6">
                                                <InputField label="Description" icon={FileText} value={item.name} onChange={e => updateItem(item.id, 'name', e.target.value)} />
                                            </div>
                                            <div className="md:col-span-2">
                                                <InputField label="Qty" type="number" icon={Hash} value={item.quantity} onChange={e => updateItem(item.id, 'quantity', parseFloat(e.target.value))} />
                                            </div>
                                            <div className="md:col-span-3">
                                                <InputField label="Price (Excl. VAT)" type="number" icon={Hash} value={item.price} onChange={e => updateItem(item.id, 'price', parseFloat(e.target.value))} />
                                            </div>
                                            <div className="md:col-span-1 flex justify-center">
                                                <button onClick={() => removeItem(item.id)} className="p-3 bg-red-50 text-red-500 rounded-2xl hover:bg-red-500 hover:text-white transition-all border border-red-100 shadow-sm">
                                                    <Trash2 size={24} />
                                                </button>
                                            </div>
                                        </motion.div>
                                    ))}
                                </div>
                            </motion.div>

                            {/* Actions */}
                            <div className="flex flex-col items-center gap-8 pt-6 pb-20">
                                {error && (
                                    <motion.div initial={{ opacity: 0, scale: 0.9 }} animate={{ opacity: 1, scale: 1 }} className="p-5 bg-red-50 border border-red-100 rounded-2xl text-red-600 flex items-center gap-4 w-full shadow-sm font-bold">
                                        <AlertCircle size={24} /> <span>{error}</span>
                                    </motion.div>
                                )}

                                <button
                                    onClick={handleSubmit}
                                    disabled={loading}
                                    className="group relative w-full md:w-[400px] h-[72px] bg-slate-900 text-white rounded-[2rem] font-black text-xl hover:bg-indigo-600 transition-all duration-500 flex items-center justify-center gap-4 disabled:opacity-50 disabled:cursor-not-allowed shadow-2xl shadow-slate-200 overflow-hidden"
                                >
                                    <div className="absolute inset-0 bg-gradient-to-r from-indigo-600 to-blue-600 opacity-0 group-hover:opacity-100 transition-opacity duration-500"></div>
                                    <span className="relative flex items-center gap-4">
                                        {loading ? <Loader2 className="animate-spin" size={24} /> : <Send size={24} />}
                                        {loading ? 'CRYPTOGRAPHIC SIGNING...' : 'SIGN & TRANSMIT'}
                                    </span>
                                </button>
                            </div>
                        </motion.div>
                    ) : (
                        <motion.div
                            key="list-view"
                            initial={{ opacity: 0, x: 20 }}
                            animate={{ opacity: 1, x: 0 }}
                            exit={{ opacity: 0, x: -20 }}
                        >
                            <InvoiceTable invoices={invoices} onView={openDetails} />
                        </motion.div>
                    )}
                </AnimatePresence>
            </div>

            {/* Selected Invoice Details Modal */}
            <AnimatePresence>
                {(selectedInvoice || result) && (
                    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 backdrop-blur-2xl bg-slate-900/40">
                        <motion.div
                            initial={{ scale: 0.9, opacity: 0 }}
                            animate={{ scale: 1, opacity: 1 }}
                            exit={{ scale: 0.9, opacity: 0 }}
                            className="bg-white rounded-[3rem] shadow-[0_32px_128px_-16px_rgba(0,0,0,0.3)] w-full max-w-4xl overflow-hidden relative"
                        >
                            <button
                                onClick={() => { setSelectedInvoice(null); setResult(null); }}
                                className="absolute top-8 right-8 p-3 hover:bg-slate-100 rounded-2xl transition-all"
                            >
                                <Plus size={24} className="rotate-45 text-slate-400" />
                            </button>

                            <div className="p-12 space-y-10">
                                <div className="text-center space-y-4">
                                    <div className="inline-flex p-5 bg-indigo-50 text-indigo-600 rounded-3xl">
                                        <CheckCircle2 size={48} />
                                    </div>
                                    <h2 className="text-4xl font-black text-slate-900 line-clamp-1">
                                        {(selectedInvoice?.compliance_results?.reportingStatus === 'REPORTED' || result?.compliance?.reportingStatus === 'REPORTED') ? 'REPORTED' : 'COMPLIANT'}
                                    </h2>
                                    <p className="text-slate-500 text-lg">Invoice authenticated via ZATCA Phase II Protocol</p>
                                </div>

                                {/* Warning Messages Section */}
                                {(selectedInvoice?.compliance_results?.validationResults?.warningMessages?.length > 0 || result?.compliance?.validationResults?.warningMessages?.length > 0) && (
                                    <div className="mx-12 p-6 bg-amber-50 border border-amber-100 rounded-3xl space-y-3">
                                        <div className="flex items-center gap-2 text-amber-600 font-bold text-sm uppercase tracking-wider">
                                            <AlertCircle size={18} /> ZATCA Warnings
                                        </div>
                                        <div className="space-y-2">
                                            {(selectedInvoice?.compliance_results?.validationResults?.warningMessages || result?.compliance?.validationResults?.warningMessages).map((warn, i) => (
                                                <div key={i} className="text-xs text-amber-700 bg-white/50 p-2 rounded-xl border border-amber-100/50 leading-relaxed">
                                                    {warn.message}
                                                </div>
                                            ))}
                                        </div>
                                    </div>
                                )}

                                <div className="grid grid-cols-1 md:grid-cols-2 gap-12 items-start px-12 pb-12">
                                    <div className="space-y-8">
                                        <div className="space-y-6">
                                            <div className="p-6 bg-slate-50 rounded-[2.5rem] border border-slate-100">
                                                <p className="text-xs font-black text-indigo-600 uppercase tracking-widest mb-2">Invoice Hash (SHA-256)</p>
                                                <p className="font-mono text-xs text-slate-600 break-all leading-relaxed">
                                                    {(selectedInvoice || result).invoice_hash}
                                                </p>
                                            </div>
                                            <div className="p-6 bg-slate-50 rounded-[2.5rem] border border-slate-100">
                                                <p className="text-xs font-black text-indigo-600 uppercase tracking-widest mb-2">Entity Reference</p>
                                                <p className="text-slate-900 font-bold uppercase tracking-tight">{(selectedInvoice || result).seller_name}</p>
                                                <p className="text-slate-400 text-sm">{(selectedInvoice || result).invoice_number}</p>
                                            </div>
                                        </div>
                                        <div className="grid grid-cols-1 gap-4">
                                            <button
                                                onClick={() => {
                                                    const inv = selectedInvoice || result;
                                                    window.location.href = `/api/zatca/invoices/${inv.id}/download-xml`;
                                                }}
                                                className="flex items-center justify-center gap-3 w-full py-5 bg-indigo-600 text-white rounded-[2rem] font-black hover:bg-indigo-700 transition-all shadow-xl shadow-indigo-100"
                                            >
                                                <Download size={20} /> EXPORT COMPLIANT XML
                                            </button>
                                            <button
                                                onClick={() => {
                                                    window.open('https://sandbox.fatoora.zatca.gov.sa/', '_blank');
                                                }}
                                                className="flex items-center justify-center gap-3 w-full py-5 bg-slate-900 text-white rounded-[2rem] font-black hover:bg-slate-800 transition-all shadow-xl shadow-slate-200"
                                            >
                                                <ExternalLink size={20} /> VIEW ON FATOORA
                                            </button>
                                        </div>
                                    </div>

                                    <div className="flex flex-col items-center gap-6">
                                        <div className="p-8 bg-white border-2 border-slate-100 rounded-[3.5rem] shadow-2xl relative group">
                                            <img
                                                src={(selectedInvoice || result).qr_url}
                                                alt="ZATCA QR"
                                                className="w-72 h-72 rounded-2xl"
                                            />
                                            <div className="absolute inset-0 bg-indigo-600 opacity-0 group-hover:opacity-10 rounded-[3.5rem] transition-opacity"></div>
                                        </div>
                                        <div className="text-center space-y-1">
                                            <p className="text-lg font-black text-slate-900 uppercase tracking-tight">Cryptographic Stamp</p>
                                            <p className="text-slate-400 text-sm font-medium leading-relaxed max-w-[240px]">
                                                Contains Phase II TLV tags for digital verification
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </motion.div>
                    </div>
                )}
            </AnimatePresence>
        </div>
    );
}
