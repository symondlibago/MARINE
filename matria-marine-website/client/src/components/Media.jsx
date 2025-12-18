import React, { useEffect, useState, useCallback } from 'react';
import { Plus, Upload, X, Loader2, Trash2, Edit2, CheckCircle, AlertTriangle, Save } from 'lucide-react';
import { mediaAPI } from '../pages/api'; 

// Hardcoded data
const hardcodedCategories = [
  {
    title: "Cylinder Liner Reconditioning",
    description: "Expert reconditioning services to restore cylinder liners to their optimal geometric specifications, ensuring extended engine life and peak performance.",
    items: [
      { type: 'img', src: '/cylinder1.jpg' },
      { type: 'img', src: '/cylinder2.jpg' },
      { type: 'img', src: '/cylinder3.png' },
      { type: 'img', src: '/cylinder4.jpg' },
      { type: 'img', src: '/cylinder5.png' },
      { type: 'img', src: '/cylinder6.png' },
      { type: 'img', src: '/cylinder7.jpg' },
      { type: 'img', src: '/cylinder8.jpg' },
      { type: 'img', src: '/cylinder9.jpg' },
    ],
    isHardcoded: true
  },
  {
    title: "Hydraulic Cylinder Repair",
    description: "Comprehensive repair and testing of hydraulic cylinders, including chroming, honing, and seal replacement for heavy-duty maritime applications.",
    items: [
      { type: 'img', src: '/hydraulic1.JPG' },
      { type: 'img', src: '/hydraulic2.JPG' },
      { type: 'img', src: '/hydraulic4.JPG' },
      { type: 'img', src: '/hydraulic5.JPG' },
      { type: 'vid', src: '/hydraulic6.MP4' },
      { type: 'vid', src: '/hydraulic7.MP4' },
    ],
    isHardcoded: true

  },
  {
    title: "Stern Tube Repair",
    description: "Specialized stern tube seal repair and bonding services performed in-situ or dry dock, guaranteeing watertight integrity and propulsion efficiency.",
    items: [
      { type: 'img', src: '/stern1.JPG' },
      { type: 'img', src: '/stern2.JPG' },
      { type: 'img', src: '/stern3.JPG' },
      { type: 'img', src: '/stern4.JPG' },
      { type: 'img', src: '/stern5.JPG' },
      { type: 'img', src: '/stern6.JPG' },
      { type: 'img', src: '/stern7.JPG' },
      { type: 'img', src: '/stern8.JPG' },
      { type: 'img', src: '/stern9.JPG' },
      { type: 'img', src: '/stern10.JPG' },
      { type: 'img', src: '/stern11.JPG' },
      { type: 'img', src: '/stern12.JPG' },
      { type: 'vid', src: '/stern13.MP4' },
    ],
    isHardcoded: true
  },
  {
    title: "Valve Repair",
    description: "Precision overhaul and lapping of various marine valves, ensuring leak-free operation and compliance with safety standards.",
    items: [
      { type: 'img', src: '/valve1.JPG' },
      { type: 'vid', src: '/valve2.MP4' },
      { type: 'img', src: '/valve3.JPG' },
      { type: 'img', src: '/valve4.JPG' },
      { type: 'vid', src: '/valve5.MP4' },
      { type: 'img', src: '/valve6.JPG' },
      { type: 'img', src: '/valve7.JPG' },
    ],
    isHardcoded: true

  }
];

export default function Media() {
  const [categories, setCategories] = useState(hardcodedCategories);
  const [isAdmin, setIsAdmin] = useState(false);
  
  // --- Modals & UI State ---
  const [isUploadModalOpen, setIsUploadModalOpen] = useState(false);
  const [showSuccessModal, setShowSuccessModal] = useState(false);
  const [successMessage, setSuccessMessage] = useState('');
  
  // Delete States
  const [deletingItem, setDeletingItem] = useState(null); // { itemId }
  const [deletingCategory, setDeletingCategory] = useState(null); // { catId, title }
  const [isDeleting, setIsDeleting] = useState(false); 

  // Upload Form State
  const [uploadForm, setUploadForm] = useState({ categoryId: null, title: '', description: '' });
  const [selectedFile, setSelectedFile] = useState(null);
  const [previewUrl, setPreviewUrl] = useState(null);
  const [isUploading, setIsUploading] = useState(false);
  const [isNewCategoryMode, setIsNewCategoryMode] = useState(false);

  // Editing State
  const [editingCategoryData, setEditingCategoryData] = useState(null); 
  const [isSavingUpdate, setIsSavingUpdate] = useState(false);

  useEffect(() => {
    window.scrollTo(0, 0);
    checkLoginStatus();
    fetchMediaData();
  }, []);

  const checkLoginStatus = () => {
    const user = sessionStorage.getItem('user_name'); 
    setIsAdmin(!!user);
  };

  const fetchMediaData = useCallback(async () => {
    try {
      const response = await mediaAPI.getAll();
      if (response.data.success) {
        const dbCategories = response.data.data;
        
        let finalCats = hardcodedCategories.map(cat => ({...cat, isHardcoded: true}));

        dbCategories.forEach(dbCat => {
          const existingIndex = finalCats.findIndex(c => c.title.toLowerCase() === dbCat.title.toLowerCase());
          
          // --- UPDATED: Sort items by ID descending (newest first) before mapping ---
          const sortedItems = dbCat.items.sort((a, b) => b.id - a.id);

          const dbItemsFormatted = sortedItems.map(item => ({
              id: item.id,
              type: item.type,
              src: item.src 
          }));

          if (existingIndex > -1) {
            finalCats[existingIndex] = {
                ...finalCats[existingIndex],
                id: dbCat.id,
                description: dbCat.description,
                // --- UPDATED: Put new DB items BEFORE hardcoded items ---
                items: [...dbItemsFormatted, ...finalCats[existingIndex].items],
                isHardcoded: false 
            };
          } else {
            // For new categories, since we loop through dbCategories (usually sorted by created_at desc in backend),
            // simply using unshift ensures the newest category is at top.
            finalCats.unshift({ 
              id: dbCat.id,
              title: dbCat.title,
              description: dbCat.description,
              items: dbItemsFormatted,
              isHardcoded: false
            });
          }
        });
        setCategories(finalCats);
      }
    } catch (error) {
      console.error("Failed to load media:", error);
    }
  }, []);


  // --- Modals ---
  const openUploadModal = (category = null) => {
    if (category) {
        setUploadForm({ categoryId: category.id, title: category.title, description: category.description });
        setIsNewCategoryMode(false);
    } else {
        setUploadForm({ categoryId: null, title: '', description: '' });
        setIsNewCategoryMode(true);
    }
    setSelectedFile(null);
    setPreviewUrl(null);
    setIsUploadModalOpen(true);
  };

  const closeAllModals = () => {
    setIsUploadModalOpen(false);
    setDeletingItem(null);
    setDeletingCategory(null);
    setShowSuccessModal(false);
    setEditingCategoryData(null);
  };

  const triggerSuccess = (msg) => {
      closeAllModals();
      setSuccessMessage(msg);
      setShowSuccessModal(true);
      setTimeout(() => { setShowSuccessModal(false); }, 3000);
  }

  // --- Upload ---
  const handleFileChange = (e) => {
    const file = e.target.files[0];
    if (file) {
      setSelectedFile(file);
      setPreviewUrl(URL.createObjectURL(file));
    }
  };

  const handleUpload = async (e) => {
    e.preventDefault();
    if (!selectedFile) { alert("Please select a file."); return; }
    if (!uploadForm.title) { alert("Title is missing."); return; }

    setIsUploading(true);
    
    const formData = new FormData();
    formData.append('title', uploadForm.title);
    formData.append('description', uploadForm.description || '');
    if (uploadForm.categoryId) {
       formData.append('category_id', uploadForm.categoryId);
    }
    formData.append('file', selectedFile);

    try {
      const res = await mediaAPI.uploadItem(formData);
      if (res.data.success) {
        triggerSuccess('Media uploaded successfully!');
        fetchMediaData(); 
      }
    } catch (err) {
      alert(`Error: ${err.response?.data?.message || err.message}`);
    } finally {
      setIsUploading(false);
    }
  };


  // --- Delete Item ---
  const initiateDeleteItem = (itemId) => {
      setDeletingItem(itemId);
  };

  const confirmDeleteItem = async () => {
      if(!deletingItem) return;
      setIsDeleting(true);
      try {
          const res = await mediaAPI.deleteItem(deletingItem);
          if(res.data.success) {
              triggerSuccess('Media item deleted.');
              fetchMediaData();
          }
      } catch (err) {
           alert(`Delete failed: ${err.response?.data?.message || err.message}`);
      } finally {
           setIsDeleting(false);
           setDeletingItem(null);
      }
  }

  // --- Delete Category ---
  const initiateDeleteCategory = (category) => {
      setDeletingCategory({ id: category.id, title: category.title });
  }

  const confirmDeleteCategory = async () => {
      if(!deletingCategory) return;
      setIsDeleting(true);
      try {
          const res = await mediaAPI.deleteCategory(deletingCategory.id);
          if(res.data.success) {
              triggerSuccess('Category deleted successfully.');
              fetchMediaData();
          }
      } catch (err) {
           alert(`Category delete failed: ${err.response?.data?.message || err.message}`);
      } finally {
           setIsDeleting(false);
           setDeletingCategory(null);
      }
  }


  // --- Update Category ---
  const startEditing = (category) => {
      if (!category.id) return; 
      setEditingCategoryData({
          id: category.id,
          title: category.title,
          description: category.description
      });
  };

  const handleEditFormChange = (field, value) => {
      setEditingCategoryData(prev => ({ ...prev, [field]: value }));
  };

  const saveCategoryUpdate = async () => {
      if(!editingCategoryData || !editingCategoryData.title) return;
      setIsSavingUpdate(true);
      try {
          const res = await mediaAPI.updateCategory(editingCategoryData.id, {
              title: editingCategoryData.title,
              description: editingCategoryData.description
          });
          if(res.data.success) {
              triggerSuccess('Category updated successfully.');
              fetchMediaData();
          }
      } catch (err) {
           alert(`Update failed: ${err.response?.data?.message || err.message}`);
      } finally {
          setIsSavingUpdate(false);
          setEditingCategoryData(null);
      }
  }

  const fadeUpClass = "animate-fade-in-up";

  return (
    <>
      <style>
        {`
          @import url('https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700&family=Raleway:wght@400;600&display=swap');
          .font-playfair { font-family: 'Playfair Display', serif; }
          .font-raleway { font-family: 'Raleway', sans-serif; }
          .animate-fade-in-up { animation: fadeInUp 0.5s ease-out; }
          @keyframes fadeInUp { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
        `}
      </style>

      {/* --- Success Modal --- */}
      {showSuccessModal && (
        <div className="fixed inset-0 z-[70] flex items-end sm:items-start justify-center px-4 py-6 pointer-events-none sm:p-6">
            <div className="max-w-sm w-full bg-white shadow-lg rounded-lg pointer-events-auto ring-1 ring-black ring-opacity-5 overflow-hidden animate-fade-in-up">
              <div className="p-4 flex">
                  <CheckCircle className="h-6 w-6 text-green-400" />
                  <div className="ml-3 w-0 flex-1 pt-0.5">
                    <p className="text-sm font-medium text-gray-900">Success!</p>
                    <p className="mt-1 text-sm text-gray-500">{successMessage}</p>
                  </div>
              </div>
            </div>
        </div>
      )}

      {/* --- Delete ITEM Confirmation Modal --- */}
      {deletingItem && (
          <div className="fixed inset-0 z-[65] flex items-center justify-center bg-black/50 backdrop-blur-sm p-4">
             <div className="bg-white rounded-lg p-6 max-w-sm w-full animate-fade-in-up">
                <div className="flex items-center justify-center h-12 w-12 rounded-full bg-red-100 mx-auto mb-4">
                    <AlertTriangle className="h-6 w-6 text-red-600" />
                </div>
                <h3 className="text-lg font-medium text-center mb-2">Delete Media Item</h3>
                <p className="text-sm text-gray-500 text-center mb-6">Are you sure? This cannot be undone.</p>
                <div className="flex gap-3">
                  <button onClick={closeAllModals} disabled={isDeleting} className="flex-1 py-2 border rounded-lg hover:bg-gray-50">Cancel</button>
                  <button onClick={confirmDeleteItem} disabled={isDeleting} className="flex-1 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 flex justify-center items-center">
                    {isDeleting ? <Loader2 className="animate-spin h-4 w-4"/> : 'Delete'}
                  </button>
                </div>
             </div>
          </div>
      )}

      {/* --- Delete CATEGORY Confirmation Modal --- */}
      {deletingCategory && (
          <div className="fixed inset-0 z-[65] flex items-center justify-center bg-black/50 backdrop-blur-sm p-4">
             <div className="bg-white rounded-lg p-6 max-w-sm w-full animate-fade-in-up">
                <div className="flex items-center justify-center h-12 w-12 rounded-full bg-red-100 mx-auto mb-4">
                    <AlertTriangle className="h-6 w-6 text-red-600" />
                </div>
                <h3 className="text-lg font-medium text-center mb-2">Delete Category?</h3>
                <p className="text-sm font-bold text-gray-800 text-center mb-1">{deletingCategory.title}</p>
                <p className="text-xs text-gray-500 text-center mb-6">Warning: This will delete the category and ALL images inside it. This cannot be undone.</p>
                <div className="flex gap-3">
                  <button onClick={closeAllModals} disabled={isDeleting} className="flex-1 py-2 border rounded-lg hover:bg-gray-50">Cancel</button>
                  <button onClick={confirmDeleteCategory} disabled={isDeleting} className="flex-1 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 flex justify-center items-center">
                    {isDeleting ? <Loader2 className="animate-spin h-4 w-4"/> : 'Delete All'}
                  </button>
                </div>
             </div>
          </div>
      )}

      {/* --- Upload Modal --- */}
      {isUploadModalOpen && (
        <div className="fixed inset-0 z-[60] flex items-center justify-center bg-black/60 backdrop-blur-sm p-4">
          <div className="bg-white rounded-2xl shadow-2xl w-full max-w-lg overflow-hidden animate-fade-in-up">
            <div className="px-8 py-6 border-b border-gray-100 flex justify-between items-center">
              <h3 className="font-playfair font-bold text-2xl text-gray-800">
                {isNewCategoryMode ? 'New Category & Media' : 'Add Media'}
              </h3>
              <button onClick={closeAllModals} className="text-gray-400 hover:text-gray-600"><X size={24}/></button>
            </div>
            
            <form onSubmit={handleUpload} className="p-8 space-y-6">
               {!isNewCategoryMode && <p className="text-gray-600">Adding to: <span className="font-semibold">{uploadForm.title}</span></p>}

               {isNewCategoryMode && (
                 <>
                    <div>
                        <label className="block text-sm font-bold text-gray-700 mb-2">Category Title <span className="text-red-500">*</span></label>
                        <input className="w-full border-gray-300 bg-gray-50 rounded-lg p-3" value={uploadForm.title} onChange={e => setUploadForm({...uploadForm, title: e.target.value})} placeholder="e.g. Hull Cleaning" required />
                    </div>
                    <div>
                        <label className="block text-sm font-bold text-gray-700 mb-2">Description</label>
                        <textarea className="w-full border-gray-300 bg-gray-50 rounded-lg p-3" value={uploadForm.description} onChange={e => setUploadForm({...uploadForm, description: e.target.value})} rows="3" />
                    </div>
                 </>
               )}
               
               <div className={`border-2 border-dashed rounded-xl p-6 text-center cursor-pointer relative ${previewUrl ? 'border-[#cebd88]' : 'border-gray-300'}`}>
                  <input type="file" onChange={handleFileChange} className="absolute inset-0 opacity-0 cursor-pointer z-10" accept="image/*,video/*" required={!selectedFile} />
                  {previewUrl ? (
                    <img src={previewUrl} className="h-48 mx-auto object-contain" alt="Preview" />
                  ) : (
                    <div className="text-gray-500 py-8"><Upload className="mx-auto w-12 h-12 mb-3 text-[#cebd88]" /><p>Click to upload</p></div>
                  )}
               </div>

               <button type="submit" disabled={isUploading} className="w-full bg-[#cebd88] hover:bg-[#b5a36e] text-[#28364b] font-bold py-4 rounded-xl flex justify-center gap-2">
                 {isUploading ? <Loader2 className="animate-spin" /> : 'Confirm Upload'}
               </button>
            </form>
          </div>
        </div>
      )}

      {/* ================= MAIN CONTENT ================= */}

      <section className="min-h-screen bg-[#F1F0E8] py-24">
        <div className="container mx-auto px-4 md:px-8 lg:px-12">
          
          <div className={`text-center mb-20 relative ${fadeUpClass}`}>
            <h1 className="text-3xl md:text-5xl lg:text-6xl font-bold font-playfair text-[#28364b] mb-6">
              Elevate Your Maritime Operations with <br className="hidden md:block"/> Matria Marine Services
            </h1>
            <div className="w-24 h-1 bg-[#cebd88] mx-auto rounded-full"/>
            
            {isAdmin && (
                <button onClick={() => openUploadModal(null)} className="absolute top-0 right-0 hidden lg:flex items-center gap-2 bg-[#28364b] text-white px-6 py-3 rounded-xl hover:bg-[#3c4a63] shadow-lg">
                    <Plus size={20} /> <span>New Category</span>
                </button>
            )}
          </div>

          <div className="space-y-24">
            {categories.map((cat, index) => {
              if (!cat) return null;
              const isEditingThisCat = editingCategoryData && cat.id && editingCategoryData.id === cat.id;
              
              // CHECK: Is this a default hardcoded category?
              const isDefaultCategory = hardcodedCategories.some(hc => hc.title === cat.title);
              // Only show Delete Category button if it's NOT a default category and HAS a DB ID
              const showDeleteCategoryButton = isAdmin && cat.id && !isDefaultCategory;

              return (
              <div key={index} className={`bg-white rounded-3xl shadow-xl p-8 md:p-12 border border-white/50 relative group-section ${fadeUpClass}`} style={{animationDelay: `${index * 0.1}s`}}>
                
                {/* Admin Controls */}
                {isAdmin && (
                    <div className="absolute top-8 right-8 flex gap-2">
                        {!isEditingThisCat && (
                            <>
                                {cat.id && (
                                  <button onClick={() => startEditing(cat)} className="bg-gray-100 hover:bg-gray-200 text-gray-600 p-3 rounded-full" title="Edit Details">
                                      <Edit2 size={20} />
                                  </button>
                                )}
                                {showDeleteCategoryButton && (
                                   <button onClick={() => initiateDeleteCategory(cat)} className="bg-red-50 hover:bg-red-100 text-red-500 p-3 rounded-full" title="Delete Entire Category">
                                      <Trash2 size={20} />
                                  </button>
                                )}
                                <button onClick={() => openUploadModal(cat)} className="bg-[#cebd88]/20 hover:bg-[#cebd88] text-[#28364b] p-3 rounded-full" title="Add Media">
                                    <Plus size={20} />
                                </button>
                            </>
                        )}
                    </div>
                )}

                {/* Category Text */}
                <div className="mb-10 pr-20">
                  {isEditingThisCat ? (
                      <div className="space-y-4 animate-fade-in-up">
                          <input className="w-full text-2xl md:text-3xl font-bold font-playfair border-b-2 border-[#cebd88] bg-transparent py-2" value={editingCategoryData.title} onChange={e => handleEditFormChange('title', e.target.value)} />
                          <textarea className="w-full font-raleway text-lg border rounded-lg p-3" rows="3" value={editingCategoryData.description} onChange={e => handleEditFormChange('description', e.target.value)} />
                          <div className="flex gap-3 pt-2">
                              <button onClick={saveCategoryUpdate} disabled={isSavingUpdate} className="flex items-center gap-2 bg-[#cebd88] px-5 py-2 rounded-lg font-bold">
                                  {isSavingUpdate ? <Loader2 className="animate-spin" size={18}/> : <Save size={18} />} Save
                              </button>
                              <button onClick={() => setEditingCategoryData(null)} disabled={isSavingUpdate} className="px-5 py-2 rounded-lg font-bold border">Cancel</button>
                          </div>
                      </div>
                  ) : (
                      <>
                        <h2 className="text-2xl md:text-3xl font-bold font-playfair text-[#28364b] mb-4">{cat.title}</h2>
                        <p className="font-raleway text-[#28364b] text-lg leading-relaxed max-w-4xl">{cat.description}</p>
                      </>
                  )}
                </div>

                {/* Media Grid */}
                <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-8">
                  {cat.items && cat.items.map((item, i) => {
                     const isDeletable = isAdmin && item.id;
                     return (
                    <div key={i} className="group relative rounded-2xl overflow-hidden shadow-md aspect-[4/3] bg-gray-200">
                      {item.type === 'img' ? (
                        <img src={item.src} alt={cat.title} className="w-full h-full object-cover group-hover:scale-105 transition-transform duration-700" loading="lazy" />
                      ) : (
                        <video src={item.src} className="w-full h-full object-cover" controls muted playsInline />
                      )}
                      {item.type === 'img' && <div className="absolute inset-0 bg-gradient-to-t from-[#28364b]/60 to-transparent opacity-0 group-hover:opacity-100 transition-opacity" />}
                      {isDeletable && (
                          <button onClick={() => initiateDeleteItem(item.id)} className="absolute top-4 right-4 bg-red-500/80 text-white p-2.5 rounded-full opacity-0 group-hover:opacity-100 transition-all translate-y-4 group-hover:translate-y-0 shadow-lg z-20">
                              <Trash2 size={18} />
                          </button>
                      )}
                    </div>
                  )})}
                </div>
              </div>
            )})}
          </div>
        </div>
      </section>
    </>
  );
}